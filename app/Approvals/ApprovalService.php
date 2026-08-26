<?php

declare(strict_types=1);

namespace BMT\Approvals;

use BMT\Database;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class ApprovalService
{
    public function propose(array $change): string
    {
        foreach (['change_type', 'resource_type', 'reason'] as $required) {
            if (empty($change[$required])) {
                throw new RuntimeException("Missing required change field: {$required}");
            }
        }

        $uuid = function_exists('Ramsey\\Uuid\\Uuid::uuid4') ? Uuid::uuid4()->toString() : bin2hex(random_bytes(16));
        $status = ($change['risk_level'] ?? 'medium') === 'low' ? 'planned' : 'pending_approval';

        $stmt = Database::connection()->prepare('INSERT INTO automation_changes (change_uuid, change_type, resource_type, resource_name, resource_id, reason, before_state, after_state, risk_level, status, reversible) VALUES (:change_uuid, :change_type, :resource_type, :resource_name, :resource_id, :reason, :before_state, :after_state, :risk_level, :status, :reversible)');
        $stmt->execute([
            'change_uuid' => $uuid,
            'change_type' => $change['change_type'],
            'resource_type' => $change['resource_type'],
            'resource_name' => $change['resource_name'] ?? null,
            'resource_id' => $change['resource_id'] ?? null,
            'reason' => $change['reason'],
            'before_state' => isset($change['before_state']) ? json_encode($change['before_state'], JSON_THROW_ON_ERROR) : null,
            'after_state' => isset($change['after_state']) ? json_encode($change['after_state'], JSON_THROW_ON_ERROR) : null,
            'risk_level' => $change['risk_level'] ?? 'medium',
            'status' => $status,
            'reversible' => !empty($change['reversible']) ? 1 : 0,
        ]);

        return $uuid;
    }

    public function approve(string $uuid, string $approvedBy): void
    {
        $stmt = Database::connection()->prepare("UPDATE automation_changes SET status = 'planned', approved_by = :approved_by, approved_at = NOW() WHERE change_uuid = :uuid AND status = 'pending_approval'");
        $stmt->execute(['approved_by' => $approvedBy, 'uuid' => $uuid]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Change was not awaiting approval.');
        }
    }

    public function reject(string $uuid): void
    {
        $stmt = Database::connection()->prepare("UPDATE automation_changes SET status = 'rejected' WHERE change_uuid = :uuid AND status IN ('planned','pending_approval')");
        $stmt->execute(['uuid' => $uuid]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Change could not be rejected.');
        }
    }
}
