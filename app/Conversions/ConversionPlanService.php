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
