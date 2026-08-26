<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use BMT\Database;

final class ReportingService
{
    public function collectYesterday(): array
    {
        $client = Client::make();
        $customerId = Client::customerId();
        $service = $client->getGoogleAdsServiceClient();
        $date = (new \DateTimeImmutable('yesterday', new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'Europe/London')))->format('Y-m-d');

        $query = "SELECT campaign.id, campaign.name, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date = '{$date}' AND campaign.status != REMOVED";

        $rows = [];
        $db = Database::connection();
        $upsert = $db->prepare('INSERT INTO daily_metrics (metric_date, scope_type, scope_id, scope_name, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (:metric_date, :scope_type, :scope_id, :scope_name, :impressions, :clicks, :cost_micros, :conversions, :conversion_value) ON DUPLICATE KEY UPDATE scope_name = VALUES(scope_name), impressions = VALUES(impressions), clicks = VALUES(clicks), cost_micros = VALUES(cost_micros), conversions = VALUES(conversions), conversion_value = VALUES(conversion_value)');

        foreach ($service->search($customerId, $query)->iterateAllElements() as $row) {
            $campaign = $row->getCampaign();
            $metrics = $row->getMetrics();
            $record = [
                'metric_date' => $date,
                'scope_type' => 'campaign',
                'scope_id' => (string) $campaign->getId(),
                'scope_name' => $campaign->getName(),
                'impressions' => (int) $metrics->getImpressions(),
                'clicks' => (int) $metrics->getClicks(),
                'cost_micros' => (string) $metrics->getCostMicros(),
                'conversions' => (string) $metrics->getConversions(),
                'conversion_value' => (string) $metrics->getConversionsValue(),
            ];
            $upsert->execute($record);
            $rows[] = $record;
        }

        return ['metric_date' => $date, 'campaigns' => $rows, 'count' => count($rows)];
    }
}
