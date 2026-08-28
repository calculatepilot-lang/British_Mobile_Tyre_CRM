<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use BMT\Database;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;

final class ReportingService
{
    public function collectYesterday(): array
    {
        $client = Client::make();
        $customerId = Client::customerId();
        $service = $client->getGoogleAdsServiceClient();
        $date = (new \DateTimeImmutable('yesterday', new \DateTimeZone(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London')))->format('Y-m-d');

        $query = "SELECT campaign.id, campaign.name, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date = '{$date}' AND campaign.status != REMOVED";

        $rows = [];
        $db = Database::connection();
        $upsert = $db->prepare(
            'INSERT INTO daily_campaign_metrics (metric_date, campaign_id, campaign_name, impressions, clicks, cost_micros, conversions, conversion_value)
             VALUES (:metric_date, :campaign_id, :campaign_name, :impressions, :clicks, :cost_micros, :conversions, :conversion_value)
             ON DUPLICATE KEY UPDATE campaign_id = VALUES(campaign_id), impressions = VALUES(impressions), clicks = VALUES(clicks),
                 cost_micros = VALUES(cost_micros), conversions = VALUES(conversions), conversion_value = VALUES(conversion_value)'
        );

        foreach ($service->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => $query,
        ]))->iterateAllElements() as $row) {
            $campaign = $row->getCampaign();
            $metrics = $row->getMetrics();
            $record = [
                'metric_date' => $date,
                'campaign_id' => (string) $campaign->getId(),
                'campaign_name' => $campaign->getName(),
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
