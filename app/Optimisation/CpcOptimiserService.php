<?php

declare(strict_types=1);

namespace BMT\Optimisation;

use BMT\Approvals\ApprovalService;
use BMT\Database;

/**
 * Keyword-level counterpart to OptimiserService (which only handles
 * campaign-level BUDGET changes). This decides which individual KEYWORDS
 * deserve a higher or lower CPC bid, based on the same underlying signal
 * (clicks with zero conversions vs. proven conversions) but at keyword
 * granularity — a campaign can look healthy in aggregate while carrying
 * several keywords burning budget with nothing to show for it, or a few
 * strong performers worth bidding up individually.
 *
 * Like OptimiserService, this only ever WRITES a proposal to
 * automation_changes — nothing is applied to Google Ads here.
 */
final class CpcOptimiserService
{
    private array $rules;
    private ApprovalService $approvals;

    public function __construct(
        private Database $database,
        ?array $rules = null,
        ?ApprovalService $approvals = null
    ) {
        $this->rules = $rules ?? require dirname(__DIR__, 2) . '/config/automation_rules.php';
        $this->approvals = $approvals ?? new ApprovalService();
    }

    /**
     * Reads daily_keyword_metrics for the given date and proposes a bid
     * change for any keyword that meets either rule:
     * - At least `minimum_clicks_for_decision` clicks and zero conversions
     *   -> propose a bid DECREASE of `max_auto_bid_change_percent`.
     * - At least `minimum_conversions_for_scale` conversions
     *   -> propose a bid INCREASE of `max_auto_bid_change_percent`.
     * A keyword already awaiting an open bid-change proposal is skipped,
     * so re-running the job the same day never duplicates.
     *
     * @return string[] change_uuids of newly queued proposals
     */
    public function queueDailyBidOptimisations(string $date): array
    {
        $minClicks = (int) ($this->rules['minimum_clicks_for_decision'] ?? 30);
        $minConversionsToScale = (float) ($this->rules['minimum_conversions_for_scale'] ?? 3);
        $maxBidChangePercent = (float) ($this->rules['max_auto_bid_change_percent'] ?? 10);

        $stmt = \BMT\Database::connection()->prepare(
            'SELECT * FROM daily_keyword_metrics WHERE metric_date = :metric_date'
        );
        $stmt->execute(['metric_date' => $date]);
        $keywords = $stmt->fetchAll();

        $queued = [];
        foreach ($keywords as $kw) {
            $resourceName = (string) $kw['criterion_resource_name'];
            $clicks = (int) $kw['clicks'];
            $conversions = (float) $kw['conversions'];
            $cost = ((int) $kw['cost_micros']) / 1_000_000;
            $currentBidMicros = (int) $kw['current_cpc_bid_micros'];
            $label = $kw['keyword_text'] . ' [' . $kw['match_type'] . '] (' . $kw['ad_group_name'] . ')';

            if ($currentBidMicros <= 0) {
                // No explicit keyword-level bid set (inheriting from ad
                // group/portfolio strategy) — nothing for this optimizer
                // to adjust at the keyword level.
                continue;
            }

            $direction = null;
            $reason = '';
            if ($clicks >= $minClicks && $conversions <= 0.0 && $cost > 0) {
                $direction = -1;
                $reason = sprintf(
                    '%d clicks and PKR %.2f spent on "%s" on %s with zero recorded conversions.',
                    $clicks, $cost, $label, $date
                );
            } elseif ($conversions >= $minConversionsToScale) {
                $direction = 1;
                $reason = sprintf(
                    '%.1f recorded conversions on "%s" on %s (spend PKR %.2f) — a higher bid may win more of this traffic.',
                    $conversions, $label, $date, $cost
                );
            }

            if ($direction === null) {
                continue;
            }
            if ($this->approvals->hasOpenProposal('google_ads_keyword_bid', $resourceName)) {
                continue;
            }

            $newBidMicros = (int) round($currentBidMicros * (1 + $direction * $maxBidChangePercent / 100));

            $queued[] = $this->approvals->propose([
                'change_type' => $direction > 0 ? 'increase_keyword_bid' : 'decrease_keyword_bid',
                'resource_type' => 'google_ads_keyword_bid',
                'resource_name' => $resourceName,
                'reason' => $reason,
                'after_state' => [
                    'metric_date' => $date,
                    'keyword_text' => $kw['keyword_text'],
                    'match_type' => $kw['match_type'],
                    'campaign_name' => $kw['campaign_name'],
                    'ad_group_name' => $kw['ad_group_name'],
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'cost' => $cost,
                    'current_cpc_bid_micros' => $currentBidMicros,
                    'new_cpc_bid_micros' => $newBidMicros,
                    'proposed_change_percent' => $direction * $maxBidChangePercent,
                ],
                'risk_level' => $maxBidChangePercent <= 10 ? 'low' : 'medium',
                'reversible' => true,
            ]);
        }

        return $queued;
    }
}
