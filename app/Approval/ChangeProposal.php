<?php

declare(strict_types=1);

namespace App\Approval;

final class ChangeProposal
{
    public function __construct(private \App\Database $database)
    {
    }

    public function create(array $proposal): int
    {
        $sql = 'INSERT INTO automation_changes
            (change_type, resource_type, resource_id, status, reason, before_state, proposed_state, created_at)
            VALUES (:change_type, :resource_type, :resource_id, :status, :reason, :before_state, :proposed_state, NOW())';
        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute([
            'change_type' => $proposal['change_type'],
            'resource_type' => $proposal['resource_type'],
            'resource_id' => $proposal['resource_id'] ?? null,
            'status' => 'pending_approval',
            'reason' => $proposal['reason'],
            'before_state' => json_encode($proposal['before_state'] ?? [], JSON_THROW_ON_ERROR),
            'proposed_state' => json_encode($proposal['proposed_state'] ?? [], JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->database->pdo()->lastInsertId();
    }
}
