<?php

declare(strict_types=1);

namespace App\Conversions;

use App\Database;

final class ConversionPlanService
{
    private array $config;

    public function __construct(private Database $database, ?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/conversions.php';
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

    public function queueProposal(array $proposal): int
    {
        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO automation_decisions
             (decision_type, resource_name, status, reversible, proposed_state, created_at)
             VALUES (:type, :resource_name, :status, :reversible, :proposed_state, NOW())'
        );
        $stmt->execute([
            'type' => $proposal['type'],
            'resource_name' => $proposal['resource_name'],
            'status' => $proposal['status'],
            'reversible' => (int) $proposal['reversible'],
            'proposed_state' => json_encode($proposal['payload'], JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->database->pdo()->lastInsertId();
    }
}
