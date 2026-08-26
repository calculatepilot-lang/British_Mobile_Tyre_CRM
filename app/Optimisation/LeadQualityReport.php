<?php

declare(strict_types=1);

namespace App\Optimisation;

use App\Database;
use PDO;

final class LeadQualityReport
{
    public function __construct(private Database $database)
    {
    }

    public function byCity(): array
    {
        return $this->groupBy('city');
    }

    public function byVehicleType(): array
    {
        return $this->groupBy('vehicle_type');
    }

    public function byCampaign(): array
    {
        $sql = 'SELECT a.campaign_name AS dimension,
                       COUNT(DISTINCT l.id) AS leads,
                       SUM(l.status IN ("qualified","quoted","booked","completed")) AS qualified_leads,
                       SUM(l.status = "completed") AS completed_leads,
                       COALESCE(SUM(l.final_revenue),0) AS revenue
                FROM leads l
                LEFT JOIN lead_attribution a ON a.lead_id = l.id
                GROUP BY a.campaign_name
                ORDER BY revenue DESC, completed_leads DESC, qualified_leads DESC';
        return $this->database->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function groupBy(string $column): array
    {
        $allowed = ['city', 'vehicle_type'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported report dimension.');
        }

        $sql = "SELECT {$column} AS dimension,
                       COUNT(*) AS leads,
                       SUM(status IN ('qualified','quoted','booked','completed')) AS qualified_leads,
                       SUM(status = 'completed') AS completed_leads,
                       COALESCE(SUM(final_revenue),0) AS revenue
                FROM leads
                GROUP BY {$column}
                ORDER BY revenue DESC, completed_leads DESC, qualified_leads DESC";
        return $this->database->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
