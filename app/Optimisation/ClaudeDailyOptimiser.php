<?php

declare(strict_types=1);

namespace BMT\Optimisation;

use BMT\Approvals\ApprovalService;
use BMT\Database;
use RuntimeException;
use Throwable;

/**
 * A second, LLM-based source of daily optimisation proposals — sitting
 * alongside OptimiserService's fixed-threshold rules, not replacing them.
 * Where the rule engine can only ever say "N clicks, 0 conversions, cut
 * budget", Claude can reason across campaign efficiency AND lead quality
 * (by city/vehicle/campaign) together and explain WHY in plain language,
 * which becomes the proposal's `reason` — visible to whoever approves it
 * on /changes.
 *
 * Safety model (this is the part that matters):
 * - Claude only ever produces recommendations in this class. It has no
 *   access to ChangeExecutor and cannot reach Google Ads directly.
 * - Every recommendation is validated against real data before becoming a
 *   proposal: the campaign name must be one that actually appeared in
 *   today's campaign_efficiency() results (never trust an LLM-supplied
 *   resource name — a hallucinated or malformed campaign name must never
 *   reach the approval queue as if it were a real resource).
 * - Percent changes are clamped to the SAME config/automation_rules.php
 *   caps (max_auto_budget_change_percent) as the rule-based optimiser —
 *   Claude cannot propose a larger change than the rules already allow.
 * - Every Claude-sourced proposal is forced to risk_level='medium' at
 *   minimum, regardless of what Claude itself estimated — this guarantees
 *   ApprovalService::propose() always sets status='pending_approval', never
 *   the 'planned' (auto-approved) path reserved for risk_level='low'. An
 *   AI-authored proposal always gets a human review, no exceptions.
 * - If the API call fails, times out, or returns anything that doesn't
 *   parse as the expected JSON shape, this method returns an empty array
 *   and logs the failure — it never falls back to inventing a
 *   recommendation, and a failure here never blocks the rule-based
 *   optimiser from still running.
 */
final class ClaudeDailyOptimiser
{
    private array $rules;
    private ApprovalService $approvals;
    private ClaudeClient $client;

    public function __construct(
        private Database $database,
        ?array $rules = null,
        ?ApprovalService $approvals = null,
        ?ClaudeClient $client = null
    ) {
        $this->rules = $rules ?? require dirname(__DIR__, 2) . '/config/automation_rules.php';
        $this->approvals = $approvals ?? new ApprovalService();
        $this->client = $client ?? new ClaudeClient();
    }

    public function isEnabled(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array{queued: string[], skipped_existing: int, recommendations_seen: int, error: ?string}
     */
    public function queueDailyOptimisations(string $date): array
    {
        if (!$this->isEnabled()) {
            return ['queued' => [], 'skipped_existing' => 0, 'recommendations_seen' => 0, 'error' => 'Claude optimisation is not enabled (set CLAUDE_OPTIMISATION_ENABLED=true and ANTHROPIC_API_KEY).'];
        }

        $intelligence = new PerformanceIntelligence($this->database);
        $campaigns = $intelligence->campaignEfficiency($date);
        if (!$campaigns) {
            return ['queued' => [], 'skipped_existing' => 0, 'recommendations_seen' => 0, 'error' => 'No campaign metrics recorded for ' . $date . ' — nothing to analyse.'];
        }

        $cityQuality = $intelligence->cityQuality();
        $vehicleQuality = $intelligence->vehicleQuality();
        $validCampaignNames = array_map(static fn(array $c): string => (string) $c['campaign_name'], $campaigns);

        try {
            $raw = $this->client->send($this->systemPrompt(), $this->buildDataPrompt($date, $campaigns, $cityQuality, $vehicleQuality));
            $recommendations = $this->parseRecommendations($raw);
        } catch (Throwable $e) {
            $this->logFailure($date, $e->getMessage());
            return ['queued' => [], 'skipped_existing' => 0, 'recommendations_seen' => 0, 'error' => $e->getMessage()];
        }

        $maxPercent = (float) ($this->rules['max_auto_budget_change_percent'] ?? 10);
        $queued = [];
        $skippedExisting = 0;

        foreach ($recommendations as $rec) {
            $campaignName = (string) ($rec['campaign_name'] ?? '');
            $action = (string) ($rec['action'] ?? '');
            $reasoning = trim((string) ($rec['reasoning'] ?? ''));

            // Hard validation gate — never trust the model's own claims about
            // what's real. A campaign name that isn't in today's actual data,
            // or an action outside the fixed whitelist, is dropped silently
            // rather than queued as a "best effort" guess.
            if (!in_array($campaignName, $validCampaignNames, true)) {
                continue;
            }
            if (!in_array($action, ['increase_budget', 'decrease_budget', 'pause_campaign', 'no_action'], true)) {
                continue;
            }
            if ($action === 'no_action' || $reasoning === '') {
                continue;
            }

            if ($this->approvals->hasOpenProposal('google_ads_campaign_budget', $campaignName)) {
                $skippedExisting++;
                continue;
            }

            $percent = max(-$maxPercent, min($maxPercent, (float) ($rec['percent'] ?? $maxPercent)));
            if ($action === 'increase_budget') $percent = abs($percent);
            if ($action === 'decrease_budget') $percent = -abs($percent);

            $changeType = $action === 'pause_campaign' ? 'pause_campaign' : ($action === 'increase_budget' ? 'increase_budget' : 'decrease_budget');

            $queued[] = $this->approvals->propose([
                'change_type' => $changeType,
                'resource_type' => 'google_ads_campaign_budget',
                'resource_name' => $campaignName,
                'reason' => '[Claude analysis, ' . $date . '] ' . mb_substr($reasoning, 0, 900),
                'after_state' => [
                    'metric_date' => $date,
                    'source' => 'claude_daily_optimiser',
                    'proposed_change_percent' => $action === 'pause_campaign' ? null : $percent,
                ],
                // Never 'low' — see class docblock. A Claude-sourced proposal
                // always requires a human approval click, regardless of how
                // confident the reasoning sounds.
                'risk_level' => $action === 'pause_campaign' ? 'high' : 'medium',
                'reversible' => true,
            ]);
        }

        return [
            'queued' => $queued,
            'skipped_existing' => $skippedExisting,
            'recommendations_seen' => count($recommendations),
            'error' => null,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are analysing one day of Google Ads performance data for a UK mobile
tyre-fitting business. You will be given campaign efficiency figures
(clicks, cost, conversions, CPA) and lead-quality figures grouped by city
and vehicle type. Recommend, for campaigns that clearly warrant it, one
of: increase_budget, decrease_budget, or pause_campaign. Only recommend a
change when the data genuinely supports it — most campaigns on most days
warrant no_action, and returning no_action for those is correct and expected.

Only ever reference campaign names that appear verbatim in the data you
were given. Never invent a campaign, city, or figure not present in the
input.

Respond with ONLY a JSON array (no prose, no markdown fences) of objects
shaped exactly like:
[{"campaign_name": "exact name from data", "action": "increase_budget|decrease_budget|pause_campaign|no_action", "percent": 10, "reasoning": "one or two sentences explaining why, referencing the actual numbers"}]

"percent" is only meaningful for increase_budget/decrease_budget (a
positive number, e.g. 10 for 10%) and can be omitted or 0 for
pause_campaign/no_action. Every recommendation MUST include "reasoning" —
a short, specific explanation a human will read before approving or
rejecting the change.
PROMPT;
    }

    private function buildDataPrompt(string $date, array $campaigns, array $cityQuality, array $vehicleQuality): string
    {
        return json_encode([
            'date' => $date,
            'campaign_efficiency' => $campaigns,
            'lead_quality_by_city' => $cityQuality,
            'lead_quality_by_vehicle_type' => $vehicleQuality,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Parses Claude's reply as a JSON array, tolerating the common case of
     * the model wrapping it in a markdown code fence despite instructions
     * not to. Anything that doesn't parse to an array is treated as zero
     * recommendations, not an error to guess around.
     */
    private function parseRecommendations(string $raw): array
    {
        $trimmed = trim($raw);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = preg_replace('/```\s*$/', '', $trimmed);

        $decoded = json_decode((string) $trimmed, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function logFailure(string $date, string $message): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
            );
            $stmt->execute([
                'context' => 'claude_daily_optimiser',
                'message' => $message,
                'payload' => json_encode(['date' => $date], JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Best-effort logging only.
        }
    }
}
