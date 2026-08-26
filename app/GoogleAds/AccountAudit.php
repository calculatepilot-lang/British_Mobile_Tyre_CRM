<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use Google\Ads\GoogleAds\V20\Services\GoogleAdsRow;

final class AccountAudit
{
    public function run(): array
    {
        $client = Client::make();
        $customerId = Client::customerId();
        $service = $client->getGoogleAdsServiceClient();

        $customerRows = $service->search($customerId, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.conversion_tracking_setting.google_ads_conversion_customer, customer.conversion_tracking_setting.conversion_tracking_status, customer.conversion_tracking_setting.conversion_tracking_id FROM customer LIMIT 1');

        $account = null;
        foreach ($customerRows->iterateAllElements() as $row) {
            $customer = $row->getCustomer();
            $setting = $customer->getConversionTrackingSetting();
            $account = [
                'customer_id' => (string) $customer->getId(),
                'name' => $customer->getDescriptiveName(),
                'currency_code' => $customer->getCurrencyCode(),
                'time_zone' => $customer->getTimeZone(),
                'conversion_customer' => $setting ? (string) $setting->getGoogleAdsConversionCustomer() : null,
                'conversion_tracking_status' => $setting ? (string) $setting->getConversionTrackingStatus() : null,
                'conversion_tracking_id' => $setting ? (string) $setting->getConversionTrackingId() : null,
            ];
        }

        $campaigns = [];
        $query = 'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign_budget.id, campaign_budget.amount_micros FROM campaign WHERE campaign.status != REMOVED ORDER BY campaign.name';
        foreach ($service->search($customerId, $query)->iterateAllElements() as $row) {
            $campaign = $row->getCampaign();
            $budget = $row->getCampaignBudget();
            $campaigns[] = [
                'id' => (string) $campaign->getId(),
                'name' => $campaign->getName(),
                'status' => (string) $campaign->getStatus(),
                'channel_type' => (string) $campaign->getAdvertisingChannelType(),
                'bidding_strategy_type' => (string) $campaign->getBiddingStrategyType(),
                'budget_id' => $budget ? (string) $budget->getId() : null,
                'budget_amount_micros' => $budget ? (string) $budget->getAmountMicros() : null,
            ];
        }

        $conversions = [];
        $conversionCustomerId = $account['conversion_customer'] ?? (string) $customerId;
        if ($conversionCustomerId !== '') {
            foreach ($service->search((int) $conversionCustomerId, 'SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.primary_for_goal, conversion_action.counting_type FROM conversion_action WHERE conversion_action.status != REMOVED ORDER BY conversion_action.name')->iterateAllElements() as $row) {
                $action = $row->getConversionAction();
                $conversions[] = [
                    'id' => (string) $action->getId(),
                    'name' => $action->getName(),
                    'status' => (string) $action->getStatus(),
                    'type' => (string) $action->getType(),
                    'category' => (string) $action->getCategory(),
                    'primary_for_goal' => $action->getPrimaryForGoal(),
                    'counting_type' => (string) $action->getCountingType(),
                ];
            }
        }

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'mode' => 'read_only',
            'account' => $account,
            'campaign_count' => count($campaigns),
            'campaigns' => $campaigns,
            'conversion_count' => count($conversions),
            'conversions' => $conversions,
        ];
    }
}
