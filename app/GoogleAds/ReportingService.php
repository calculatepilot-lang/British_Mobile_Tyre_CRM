<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use BMT\Database;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V25\Enums\KeywordMatchTypeEnum\KeywordMatchType;

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

    /**
     * Keyword-level metrics for yesterday, needed for the CPC/bid
     * optimizer to decide which individual keywords deserve a higher or
     * lower bid — daily_campaign_metrics only has campaign totals. Only
     * ENABLED keyword criteria are pulled; removed/paused ones are
     * irrelevant to a bid decision.
     */
    public function collectKeywordMetricsYesterday(): array
    {
        $client = Client::make();
        $customerId = Client::customerId();
        $service = $client->getGoogleAdsServiceClient();
        $date = (new \DateTimeImmutable('yesterday', new \DateTimeZone(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London')))->format('Y-m-d');

        $query = "SELECT campaign.id, campaign.name, ad_group.id, ad_group.name,
                    ad_group_criterion.criterion_id, ad_group_criterion.resource_name,
                    ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type,
                    ad_group_criterion.cpc_bid_micros,
                    metrics.impressions, metrics.clicks, metrics.cost_micros,
                    metrics.conversions, metrics.conversions_value
                  FROM keyword_view
                  WHERE segments.date = '{$date}'
                    AND ad_group_criterion.status = 'ENABLED'
                    AND campaign.status != 'REMOVED'";

        $rows = [];
        $db = Database::connection();
        $upsert = $db->prepare(
            'INSERT INTO daily_keyword_metrics
                (metric_date, campaign_id, campaign_name, ad_group_id, ad_group_name,
                 criterion_id, criterion_resource_name, keyword_text, match_type,
                 current_cpc_bid_micros, impressions, clicks, cost_micros, conversions, conversion_value)
             VALUES
                (:metric_date, :campaign_id, :campaign_name, :ad_group_id, :ad_group_name,
                 :criterion_id, :criterion_resource_name, :keyword_text, :match_type,
                 :current_cpc_bid_micros, :impressions, :clicks, :cost_micros, :conversions, :conversion_value)
             ON DUPLICATE KEY UPDATE
                current_cpc_bid_micros = VALUES(current_cpc_bid_micros), impressions = VALUES(impressions),
                clicks = VALUES(clicks), cost_micros = VALUES(cost_micros),
                conversions = VALUES(conversions), conversion_value = VALUES(conversion_value)'
        );

        foreach ($service->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => $query,
        ]))->iterateAllElements() as $row) {
            $campaign = $row->getCampaign();
            $adGroup = $row->getAdGroup();
            $criterion = $row->getAdGroupCriterion();
            $metrics = $row->getMetrics();
            $record = [
                'metric_date' => $date,
                'campaign_id' => (string) $campaign->getId(),
                'campaign_name' => $campaign->getName(),
                'ad_group_id' => (string) $adGroup->getId(),
                'ad_group_name' => $adGroup->getName(),
                'criterion_id' => (string) $criterion->getCriterionId(),
                'criterion_resource_name' => $criterion->getResourceName(),
                'keyword_text' => $criterion->getKeyword()->getText(),
                'match_type' => KeywordMatchType::name($criterion->getKeyword()->getMatchType()),
                'current_cpc_bid_micros' => (string) $criterion->getCpcBidMicros(),
                'impressions' => (int) $metrics->getImpressions(),
                'clicks' => (int) $metrics->getClicks(),
                'cost_micros' => (string) $metrics->getCostMicros(),
                'conversions' => (string) $metrics->getConversions(),
                'conversion_value' => (string) $metrics->getConversionsValue(),
            ];
            $upsert->execute($record);
            $rows[] = $record;
        }

        return ['metric_date' => $date, 'keywords' => $rows, 'count' => count($rows)];
    }
}
