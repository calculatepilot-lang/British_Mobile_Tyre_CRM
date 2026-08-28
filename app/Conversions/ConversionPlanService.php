<?php

declare(strict_types=1);

namespace BMT\Conversions;

use BMT\Approvals\ApprovalService;
use BMT\Database;

final class ConversionPlanService
{
    private array $config;
    private ApprovalService $approvals;

    public function __construct(private Database $database, ?array $config = null, ?ApprovalService $approvals = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/conversions.php';
        $this->approvals = $approvals ?? new ApprovalService();
    }

    /**
     * Builds proposals only. Google Ads creation must happen through a separately
     * reviewed executor after duplicate inspection and explicit approval.
     */
    public function buildMissingActionProposals(array $existingActionNames): array
    {
        $existing = array_map('mb_strtolower', $existingActionNames);
        $proposals = [];

        foreach ($this->config['actions'] as $action) {
            if (in_array(mb_strtolower($action['name']), $existing, true)) {
                continue;
            }

            if (!empty($this->config['duplicate_check_required'])
                && $this->approvals->hasOpenProposal('google_ads_conversion_action', $action['name'])) {
                continue;
            }

            $proposals[] = [
                'type' => 'create_conversion_action',
                'resource_name' => $action['name'],
                'status' => 'pending_approval',
                'reversible' => true,
                'payload' => $action,
            ];
        }

        return $proposals;
    }

    /**
     * Runs the missing-action check against a fresh Google Ads account audit
     * and queues a proposal for each gap found (skipping ones already open).
     * This is the entry point the daily audit cron calls; it never creates
     * anything in Google Ads itself — only rows in automation_changes awaiting
     * human approval, matching this CRM's audit_only safety posture.
     *
     * @return string[] change_uuids of newly queued proposals
     */
    public function auditAndQueueMissingActions(array $auditConversions): array
    {
        $existingNames = array_map(static fn(array $c): string => (string) $c['name'], $auditConversions);
        $queued = [];

        foreach ($this->buildMissingActionProposals($existingNames) as $proposal) {
            $queued[] = $this->queueProposal($proposal);
        }

        return $queued;
    }

    /**
     * Queues a conversion-action proposal for human approval via the shared
     * automation_changes table. Returns the change_uuid identifying the record.
     */
    public function queueProposal(array $proposal): string
    {
        return $this->approvals->propose([
            'change_type' => $proposal['type'],
            'resource_type' => 'google_ads_conversion_action',
            'resource_name' => $proposal['resource_name'],
            'reason' => $proposal['reason'] ?? 'New conversion action proposed from CRM config.',
            'after_state' => $proposal['payload'],
            'risk_level' => $proposal['risk_level'] ?? 'medium',
            'reversible' => $proposal['reversible'] ?? true,
        ]);
    }
}
