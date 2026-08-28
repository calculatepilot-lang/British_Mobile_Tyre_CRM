<?php

declare(strict_types=1);

namespace BMT\Approvals;

use BMT\Database;
use RuntimeException;

final class ApprovalService
{
    public function list(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::connection()
            ->query('SELECT * FROM automation_changes ORDER BY created_at DESC LIMIT ' . $limit)
            ->fetchAll();
    }

    /**
     * True if a change for this resource is already awaiting action (not yet
     * approved/rejected/executed). Callers that create proposals on a schedule
     * (e.g. a daily audit) must check this first so re-running the audit never
     * queues duplicate proposals for the same resource.
     */
    public function hasOpenProposal(string $resourceType, string $resourceName): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM automation_changes
             WHERE resource_type = :resource_type AND resource_name = :resource_name
             AND status IN ('planned','pending_approval') LIMIT 1"
        );
        $stmt->execute(['resource_type' => $resourceType, 'resource_name' => $resourceName]);

        return $stmt->fetchColumn() !== false;
    }

    /** Approved changes waiting to be applied to Google Ads. */
    public function pending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::connection()
            ->query("SELECT * FROM automation_changes WHERE status = 'planned' ORDER BY created_at ASC LIMIT " . $limit)
            ->fetchAll();
    }

    public function markExecuted(string $uuid, ?string $resourceId, array $beforeState): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE automation_changes SET status = 'executed', resource_id = COALESCE(:resource_id, resource_id),
             before_state = :before_state, executed_at = NOW() WHERE change_uuid = :uuid AND status = 'planned'"
        );
        $stmt->execute([
            'resource_id' => $resourceId,
            'before_state' => json_encode($beforeState, JSON_THROW_ON_ERROR),
            'uuid' => $uuid,
        ]);
    }

    public function markFailed(string $uuid, string $message): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE automation_changes SET status = 'failed', review_note = :note WHERE change_uuid = :uuid AND status = 'planned'"
        );
        $stmt->execute(['note' => mb_substr($message, 0, 2000), 'uuid' => $uuid]);
    }

    public function propose(array $change): string
    {
        foreach (['change_type', 'resource_type', 'reason'] as $required) {
            if (empty($change[$required])) {
                throw new RuntimeException("Missing required change field: {$required}");
            }
        }

        $uuid = $this->uuid4();
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

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
