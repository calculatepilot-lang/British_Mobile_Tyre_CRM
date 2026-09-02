<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;

/**
 * Read-only. Finds ad groups inside "BMT | Search | {city}" campaigns
 * (the naming convention CampaignPlanner::queueCampaignProposals uses) that
 * currently have zero keywords and zero ads — i.e. skeletons created by
 * ChangeExecutor::createCampaignSkeleton that are still waiting for content.
 * Never mutates anything.
 */
final class AdGroupContentAudit
{
    private const CAMPAIGN_PREFIX = 'BMT | Search | ';

    public function listAdGroupsNeedingContent(): array
    {
        $client = Client::make();
        $customerId = Client::customerId();
        $service = $client->getGoogleAdsServiceClient();

        $adGroups = [];
        $rows = $service->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => "SELECT ad_group.id, ad_group.resource_name, ad_group.name,
                        campaign.resource_name, campaign.name
                        FROM ad_group
                        WHERE campaign.status != 'REMOVED' AND ad_group.status != 'REMOVED'
                        AND campaign.name LIKE '" . self::CAMPAIGN_PREFIX . "%'",
        ]));
        foreach ($rows->iterateAllElements() as $row) {
            $adGroup = $row->getAdGroup();
            $campaign = $row->getCampaign();
            $adGroups[(string) $adGroup->getId()] = [
                'ad_group_id' => (string) $adGroup->getId(),
                'ad_group_resource_name' => $adGroup->getResourceName(),
                'ad_group_name' => $adGroup->getName(),
                'campaign_resource_name' => $campaign->getResourceName(),
                'campaign_name' => $campaign->getName(),
                'city' => trim(substr($campaign->getName(), strlen(self::CAMPAIGN_PREFIX))),
            ];
        }

        if ($adGroups === []) {
            return [];
        }

        $keywordRows = $service->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => "SELECT ad_group.id FROM ad_group_criterion
                        WHERE ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status != 'REMOVED'",
        ]));
        foreach ($keywordRows->iterateAllElements() as $row) {
            unset($adGroups[(string) $row->getAdGroup()->getId()]);
        }

        if ($adGroups === []) {
            return [];
        }

        $adRows = $service->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => "SELECT ad_group.id FROM ad_group_ad WHERE ad_group_ad.status != 'REMOVED'",
        ]));
        foreach ($adRows->iterateAllElements() as $row) {
            unset($adGroups[(string) $row->getAdGroup()->getId()]);
        }

        return array_values($adGroups);
    }
}
