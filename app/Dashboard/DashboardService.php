<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Database;
use PDO;

final class DashboardService
{
    public function __construct(private Database $database) {}

    public function overview(): array
    {
        $pdo = $this->database->pdo();
        $lead = $pdo->query("SELECT COUNT(*) leads, SUM(status IN ('qualified','quoted','booked','completed')) qualified, SUM(status='booked') booked, SUM(status='completed') completed, COALESCE(SUM(final_revenue),0) revenue FROM leads")->fetch(PDO::FETCH_ASSOC) ?: [];
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM automation_decisions WHERE status='pending_approval'")->fetchColumn();
        return array_merge($lead, ['pending_approvals' => $pending]);
    }

    public function recentChanges(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        return $this->database->pdo()->query("SELECT * FROM automation_decisions ORDER BY created_at DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    }
}
