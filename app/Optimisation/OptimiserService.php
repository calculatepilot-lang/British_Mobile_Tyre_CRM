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

    public function recommendations(string $date): array
    {
        return (new RecommendationEngine())->fromEfficiency(
            (new PerformanceIntelligence($this->database))->campaignEfficiency($date)
        );
    }

    public function queueDailyOptimisations(string $date): array
    {
        $campaigns = (new PerformanceIntelligence($this->database))->campaignEfficiency($date);
        $minClicks = (int) $this->rules['minimum_clicks_for_decision'];
        $minConversions = (float) $this->rules['minimum_conversions_for_scale'];
        $maxBudgetPct = (float) $this->rules['max_auto_budget_change_percent'];
        $minSpendBeforePause = (float) $this->rules['minimum_spend_before_pause_gbp'];
        $queued = [];

        foreach ($campaigns as $campaign) {
            $id = (string) $campaign['campaign_id'];
            $name = (string) $campaign['campaign_name'];
            $clicks = (int) $campaign['clicks'];
            $conversions = (float) $campaign['conversions'];
            $cost = (float) $campaign['cost'];

            if ($this->approvals->hasOpenProposal('google_ads_campaign', $id)) {
                continue;
            }

            // Do not pause merely because a single day has zero conversions.
            // A pause requires a stronger spend/click signal and always needs review.
            if ($clicks >= $minClicks && $conversions <= 0.0 && $cost >= $minSpendBeforePause) {
                $queued[] = $this->approvals->propose([
                    'change_type' => 'pause_campaign',
                    'resource_type' => 'google_ads_campaign',
                    'resource_name' => $name,
                    'resource_id' => $id,
                    'reason' => sprintf('%d clicks and £%.2f spend with zero recorded conversions on %s. Pause is proposed for review, not auto-executed.', $clicks, $cost, $date),
                    'before_state' => ['status' => 'ENABLED'],
                    'after_state' => ['status' => 'PAUSED', 'metric_date' => $date, 'clicks' => $clicks, 'cost_gbp' => $cost, 'conversions' => $conversions],
                    'risk_level' => 'high',
                    'reversible' => true,
                ]);
                continue;
            }

            if ($conversions >= $minConversions) {
                $queued[] = $this->approvals->propose([
                    'change_type' => 'increase_budget',
                    'resource_type' => 'google_ads_campaign_budget',
                    'resource_name' => $name,
                    'resource_id' => $id,
                    'reason' => sprintf('%.1f recorded conversions on %s with £%.2f spend. Test a conservative budget increase.', $conversions, $date, $cost),
                    'after_state' => ['change_percent' => $maxBudgetPct, 'metric_date' => $date, 'clicks' => $clicks, 'cost_gbp' => $cost, 'conversions' => $conversions],
                    'risk_level' => 'medium',
                    'reversible' => true,
                ]);
            }
        }

        return $queued;
    }
}
