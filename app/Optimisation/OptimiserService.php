<?php

declare(strict_types=1);

namespace BMT\Optimisation;

use BMT\Database;

final class OptimiserService
{
    public function __construct(private Database $database) {}

    /** Recommendations only; never mutates Google Ads. */
    public function recommendations(string $date): array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            "SELECT campaign_name, SUM(cost_micros)/1000000 AS cost, SUM(clicks) AS clicks,
                    SUM(conversions) AS conversions
             FROM daily_campaign_metrics
             WHERE metric_date = :date
             GROUP BY campaign_name"
        );
        $stmt->execute(['date' => $date]);
        $campaigns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($campaigns as $campaign) {
            $cost = (float) $campaign['cost'];
            $clicks = (int) $campaign['clicks'];
            $conversions = (float) $campaign['conversions'];
            if ($clicks >= 25 && $conversions == 0 && $cost > 0) {
                $out[] = [
                    'risk' => 'medium',
                    'campaign' => $campaign['campaign_name'],
                    'action' => 'review_search_terms_and_ad_relevance',
                    'reason' => 'Sufficient clicks with no recorded conversion for the reporting period.',
                ];
            }
        }
        return $out;
    }
}
