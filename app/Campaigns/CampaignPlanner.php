<?php

declare(strict_types=1);

namespace BMT\Campaigns;

use BMT\Approvals\ApprovalService;

final class CampaignPlanner
{
    private array $cities;
    private array $vehicles;
    private array $roads;
    private array $automation;
    private ApprovalService $approvals;

    public function __construct(?ApprovalService $approvals = null)
    {
        $this->cities = require dirname(__DIR__, 2) . '/config/cities.php';
        $this->vehicles = require dirname(__DIR__, 2) . '/config/vehicle_policy.php';
        $this->roads = require dirname(__DIR__, 2) . '/config/road_service_targeting.php';
        $this->automation = require dirname(__DIR__, 2) . '/config/automation_rules.php';
        $this->approvals = $approvals ?? new ApprovalService();
    }

    /**
     * Produces a reviewable plan only. It never mutates Google Ads.
     */
    public function build(): array
    {
        $markets = [];
        foreach ($this->cities['cities'] as $city) {
            if (!$city['enabled']) {
                continue;
            }
            $markets[] = [
                'city' => $city['name'],
                'priority' => $city['big'] ? 'high' : 'standard',
                'planning_centre' => ['lat' => $city['lat'], 'lng' => $city['lng']],
                'vehicle_types' => $this->vehicles['allowed'],
                'road_strategy' => $this->roads['enabled'] ? $this->roads['corridor_types'] : [],
                'activation' => 'review_required',
            ];
        }

        return [
            'mode' => 'plan_only',
            'country_code' => $this->cities['country_code'],
            'location_presence_only' => $this->cities['location_presence_only'],
            'markets' => $markets,
            'excluded_vehicle_terms' => $this->vehicles['negative_keyword_candidates'],
            'notes' => [
                'No campaign, ad group, keyword, location target or negative keyword is created by this planner.',
                'Road and service-area points must be independently verified and approved before activation.',
            ],
        ];
    }

    /**
     * Turns the market plan into one named Search-campaign proposal per
     * enabled city and queues each via the approval gate — mirroring how
     * ConversionPlanService handles conversion actions. Nothing is created
     * in Google Ads by this method under any circumstance; it only writes
     * rows to automation_changes for a human to review. A city is skipped
     * if a campaign with the matching name already exists in the account
     * (pass names from a fresh AccountAudit) or already has an open
     * proposal, so a re-run never duplicates.
     *
     * Budget is split across enabled cities from
     * automation_rules.max_daily_budget (the Google Ads account's own
     * currency — PKR for this account), weighted 2:1 for 'big' cities,
     * and is itself only a suggestion for the reviewer — nothing spends
     * until a human approves and a (separately built) executor runs.
     *
     * @return string[] change_uuids of newly queued proposals
     */
    public function queueCampaignProposals(array $existingCampaignNames = []): array
    {
        $plan = $this->build();
        $existing = array_map('mb_strtolower', $existingCampaignNames);
        $dailyCap = (float) $this->automation['max_daily_budget'];
        $weightUnits = 0;
        foreach ($plan['markets'] as $market) {
            $weightUnits += $market['priority'] === 'high' ? 2 : 1;
        }

        $queued = [];
        foreach ($plan['markets'] as $market) {
            $resourceName = 'BMT | Search | ' . $market['city'];

            if (in_array(mb_strtolower($resourceName), $existing, true)) {
                continue;
            }

            if ($this->approvals->hasOpenProposal('google_ads_campaign', $resourceName)) {
                continue;
            }

            $weight = $market['priority'] === 'high' ? 2 : 1;
            $suggestedDailyBudget = $weightUnits > 0 && $dailyCap > 0
                ? round(($dailyCap * $weight) / $weightUnits, 2)
                : 0.0;

            $queued[] = $this->approvals->propose([
                'change_type' => 'create_campaign',
                'resource_type' => 'google_ads_campaign',
                'resource_name' => $resourceName,
                'reason' => 'New Search campaign proposed for ' . $market['city']
                    . ' as part of city-level expansion (' . count($plan['markets']) . ' markets configured).',
                'after_state' => [
                    'campaign_name' => $resourceName,
                    'channel_type' => 'SEARCH',
                    'city' => $market['city'],
                    'priority' => $market['priority'],
                    'planning_centre' => $market['planning_centre'],
                    'suggested_daily_budget' => $suggestedDailyBudget,
                    'vehicle_types' => $market['vehicle_types'],
                    'road_strategy' => $market['road_strategy'],
                    'negative_keywords' => $plan['excluded_vehicle_terms'],
                    'location_presence_only' => $plan['location_presence_only'],
                ],
                'risk_level' => 'high',
                'reversible' => true,
            ]);
        }

        return $queued;
    }

    /**
     * Turns "still needs content" ad groups (from AdGroupContentAudit) into
     * one queued proposal per ad group: a set of RSA headlines/descriptions
     * and keyword candidates built from config/ad_copy.php templates for
     * that ad group's city. Nothing is created in Google Ads — only a row
     * in automation_changes for a human to review. Negative keywords from
     * config/vehicle_policy.php are listed for the reviewer, never applied
     * automatically (matches automatic_negative_application=false).
     *
     * A headline/description template that would exceed Google's RSA
     * character limits once {city} is substituted is silently skipped
     * rather than truncated — a truncated headline can read wrong.
     *
     * @param array $adGroupsNeedingContent from AdGroupContentAudit::listAdGroupsNeedingContent()
     * @return string[] change_uuids of newly queued proposals
     */
    public function queueAdGroupContentProposals(array $adGroupsNeedingContent): array
    {
        $adCopy = require dirname(__DIR__, 2) . '/config/ad_copy.php';
        $queued = [];

        foreach ($adGroupsNeedingContent as $adGroup) {
            $city = (string) $adGroup['city'];
            $resourceName = 'BMT | Ad content | ' . $adGroup['ad_group_name'];

            if ($this->approvals->hasOpenProposal('google_ads_ad_group_content', $resourceName)) {
                continue;
            }

            $headlines = [];
            foreach ($adCopy['headlines'] as $template) {
                $text = str_replace('{city}', $city, $template);
                if (mb_strlen($text) <= 30) {
                    $headlines[] = $text;
                }
            }
            $descriptions = [];
            foreach ($adCopy['descriptions'] as $template) {
                $text = str_replace('{city}', $city, $template);
                if (mb_strlen($text) <= 90) {
                    $descriptions[] = $text;
                }
            }
            $keywords = array_map(
                fn (string $template): array => ['text' => str_replace('{city}', $city, $template), 'match_type' => 'PHRASE'],
                $adCopy['keyword_themes']
            );

            // RSAs require at least 3 headlines and 2 descriptions. If the
            // city name is long enough that too few templates survive the
            // length check, skip this ad group rather than queue a proposal
            // that would fail on execution — flag it for manual copywriting.
            if (count($headlines) < 3 || count($descriptions) < 2) {
                continue;
            }

            $finalUrl = $adCopy['final_url_overrides'][$city] ?? $adCopy['final_url_base'];

            $queued[] = $this->approvals->propose([
                'change_type' => 'add_ad_group_content',
                'resource_type' => 'google_ads_ad_group_content',
                'resource_name' => $resourceName,
                'reason' => 'Ad group "' . $adGroup['ad_group_name'] . '" in campaign "' . $adGroup['campaign_name'] . '" has no keywords or ads yet — proposing template-based content for ' . $city . '.',
                'after_state' => [
                    'ad_group_resource_name' => $adGroup['ad_group_resource_name'],
                    'campaign_resource_name' => $adGroup['campaign_resource_name'],
                    'city' => $city,
                    'final_url' => $finalUrl,
                    'headlines' => array_slice(array_values(array_unique($headlines)), 0, 15),
                    'descriptions' => array_slice(array_values(array_unique($descriptions)), 0, 4),
                    'keywords' => $keywords,
                    'negative_keywords_suggested' => $this->vehicles['negative_keyword_candidates'],
                ],
                'risk_level' => 'medium',
                'reversible' => true,
            ]);
        }

        return $queued;
    }
}
