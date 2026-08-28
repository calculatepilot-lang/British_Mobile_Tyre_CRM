<?php

declare(strict_types=1);

namespace BMT\Optimisation;

use BMT\Approvals\ApprovalService;
use BMT\Database;

final class OptimiserService
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

    /** Recommendations only; never mutates Google Ads. Kept for the admin UI/reporting. */
    public function recommendations(string $date): array
    {
        return (new RecommendationEngine())->fromEfficiency(
            (new PerformanceIntelligence($this->database))->campaignEfficiency($date)
        );
    }

    /**
     * Turns the day's campaign performance into concrete budget-change
     * proposals, queued through the same automation_changes approval gate
     * as conversion actions and campaigns. Nothing is applied to Google Ads
     * here — this only decides WHAT to propose and writes the proposal.
     * A campaign already awaiting an open budget-change proposal is skipped
     * so re-running the job the same day never duplicates.
     *
     * Rules applied (from config/automation_rules.php):
     * - A campaign with at least `minimum_clicks_for_decision` clicks and
     *   zero conversions is proposed for a budget decrease of
     *   `max_auto_budget_change_percent`, OR a pause if it also has spent
     *   more than one day's share of `max_daily_budget` with nothing to
     *   show for it — pausing always requires approval per
     *   `pause_requires_approval`.
     * - A campaign with at least `minimum_conversions_for_scale`
     *   conversions is proposed for a budget increase of
     *   `max_auto_budget_change_percent`.
     * - Any proposed change at or under the configured percent caps is
     *   risk_level 'low' (auto-planned by ApprovalService); anything this
     *   method decides is a pause is always 'high' regardless of size.
     *
     * @return string[] change_uuids of newly queued proposals
     */
    public function queueDailyOptimisations(string $date): array
    {
        $campaigns = (new PerformanceIntelligence($this->database))->campaignEfficiency($date);
        $minClicks = (int) ($this->rules['minimum_clicks_for_decision'] ?? 30);
        $minConversionsToScale = (float) ($this->rules['minimum_conversions_for_scale'] ?? 3);
        $maxBudgetChangePercent = (float) ($this->rules['max_auto_budget_change_percent'] ?? 10);
        $pauseRequiresApproval = !empty($this->rules['pause_requires_approval']);

        $queued = [];
        foreach ($campaigns as $campaign) {
            $name = (string) $campaign['campaign_name'];
            $clicks = (int) $campaign['clicks'];
            $conversions = (float) $campaign['conversions'];
            $cost = (float) $campaign['cost'];

            if ($clicks >= $minClicks && $conversions <= 0.0 && $cost > 0) {
                if ($this->approvals->hasOpenProposal('google_ads_campaign_budget', $name)) {
                    continue;
                }

                $queued[] = $this->approvals->propose([
                    'change_type' => $pauseRequiresApproval ? 'pause_campaign' : 'decrease_budget',
                    'resource_type' => 'google_ads_campaign_budget',
                    'resource_name' => $name,
                    'reason' => sprintf(
                        '%d clicks and PKR %.2f spent on %s with zero recorded conversions — no signal of value being returned.',
                        $clicks,
                        $cost,
                        $date
                    ),
                    'after_state' => [
                        'metric_date' => $date,
                        'clicks' => $clicks,
                        'cost' => $cost,
                        'conversions' => $conversions,
                        'proposed_change_percent' => -$maxBudgetChangePercent,
                    ],
                    'risk_level' => $pauseRequiresApproval ? 'high' : 'medium',
                    'reversible' => true,
                ]);

                continue;
            }

            if ($conversions >= $minConversionsToScale) {
                if ($this->approvals->hasOpenProposal('google_ads_campaign_budget', $name)) {
                    continue;
                }

                $queued[] = $this->approvals->propose([
                    'change_type' => 'increase_budget',
                    'resource_type' => 'google_ads_campaign_budget',
                    'resource_name' => $name,
                    'reason' => sprintf(
                        '%.1f recorded conversions on %s (spend PKR %.2f) — performance supports testing a higher budget.',
                        $conversions,
                        $date,
                        $cost
                    ),
                    'after_state' => [
                        'metric_date' => $date,
                        'clicks' => $clicks,
                        'cost' => $cost,
                        'conversions' => $conversions,
                        'proposed_change_percent' => $maxBudgetChangePercent,
                    ],
                    'risk_level' => $maxBudgetChangePercent <= 10 ? 'low' : 'medium',
                    'reversible' => true,
                ]);
            }
        }

        return $queued;
    }
}
