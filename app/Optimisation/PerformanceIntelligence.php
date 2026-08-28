<?php
declare(strict_types=1);

namespace BMT\Optimisation;

use BMT\Database;
use PDO;

final class PerformanceIntelligence
{
    public function __construct(private Database $database) {}

    public function campaignEfficiency(string $date): array
    {
        $sql = "SELECT campaign_name, SUM(cost_micros)/1000000 AS cost, SUM(clicks) AS clicks, SUM(conversions) AS conversions
                FROM daily_campaign_metrics WHERE metric_date = :date GROUP BY campaign_name";
        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute(['date' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['cost'] = (float)$r['cost'];
            $r['cpa'] = ((float)$r['conversions'] > 0) ? round($r['cost'] / (float)$r['conversions'], 2) : null;
        }
        return $rows;
    }

    public function cityQuality(): array
    {
        return (new LeadQualityReport($this->database))->byCity();
    }

    public function vehicleQuality(): array
    {
        return (new LeadQualityReport($this->database))->byVehicleType();
    }
}
