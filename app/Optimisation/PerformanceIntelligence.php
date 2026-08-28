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
        $sql = "SELECT scope_id AS campaign_id, scope_name AS campaign_name,
                       SUM(cost_micros)/1000000 AS cost, SUM(clicks) AS clicks,
                       SUM(impressions) AS impressions, SUM(conversions) AS conversions,
                       SUM(conversion_value) AS conversion_value
                FROM daily_metrics
                WHERE metric_date = :date AND scope_type = 'campaign'
                GROUP BY scope_id, scope_name";
        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute(['date' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['cost'] = (float) $r['cost'];
            $r['clicks'] = (int) $r['clicks'];
            $r['impressions'] = (int) $r['impressions'];
            $r['conversions'] = (float) $r['conversions'];
            $r['conversion_value'] = (float) $r['conversion_value'];
            $r['cpa'] = $r['conversions'] > 0 ? round($r['cost'] / $r['conversions'], 2) : null;
            $r['ctr'] = $r['impressions'] > 0 ? round(($r['clicks'] / $r['impressions']) * 100, 2) : null;
        }
        unset($r);
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
