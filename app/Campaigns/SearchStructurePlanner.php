<?php

declare(strict_types=1);

namespace BMT\Campaigns;

use BMT\Approvals\ApprovalService;

/**
 * Plans the full programmatic Search structure: 10 regional campaigns
 * (config/campaign_regions.php) x 5 services (config/services.php) x 8
 * vehicle types (config/ad_group_vehicles.php) = 400 ad groups, each with
 * generated exact-match, phrase-match, near-me, location-intent and
 * vehicle+service keyword sets plus draft responsive-search-ad copy.
 *
 * This is a planner only — build() and queueStructureProposals() never call
 * a Google Ads mutate service. One proposal is queued per region (10 total),
 * each carrying its full 40-ad-group structure in after_state, reviewed on
 * /changes exactly like CampaignPlanner's per-city proposals. Once approved,
 * ChangeExecutor::createSearchStructureSkeleton() creates it for real — but
 * always PAUSED, with negative keywords left for a human to add (see that
 * method's docblock for why negatives specifically are never auto-applied).
 *
 * This coexists with CampaignPlanner's one-campaign-per-city plan rather
 * than replacing it. Running both against the same account will create two
 * parallel campaign structures covering overlapping cities — decide which
 * one to actually activate before enabling anything.
 */
final class SearchStructurePlanner
{
    private array $cityIndex;
    private array $regions;
    private array $services;
    private array $vehicles;
    private array $vehiclePolicy;
    private array $automation;
    private ApprovalService $approvals;

    public function __construct(?ApprovalService $approvals = null)
    {
        $root = dirname(__DIR__, 2);
        $citiesConfig = require $root . '/config/cities.php';
        $this->cityIndex = [];
        foreach ($citiesConfig['cities'] as $city) {
            $this->cityIndex[$city['name']] = $city;
        }
        $this->regions = require $root . '/config/campaign_regions.php';
        $this->services = require $root . '/config/services.php';
        $this->vehicles = require $root . '/config/ad_group_vehicles.php';
        $this->vehiclePolicy = require $root . '/config/vehicle_policy.php';
        $this->automation = require $root . '/config/automation_rules.php';
        $this->approvals = $approvals ?? new ApprovalService();
    }

    /**
     * @return array{campaigns: array} 10 campaigns, each with its cities,
     *   suggested daily budget, 40 ad groups (keywords + RSA copy) and
     *   recommended (not auto-applied) negative keywords.
     */
    public function build(): array
    {
        $dailyCap = (float) $this->automation['max_daily_budget'];
        $weightUnits = 0;
        $regionWeights = [];
        foreach ($this->regions as $region) {
            $weight = $this->regionWeight($region);
            $regionWeights[$region['key']] = $weight;
            $weightUnits += $weight;
        }

        $campaigns = [];
        foreach ($this->regions as $region) {
            $cities = $this->resolveCities($region['cities']);
            $weight = $regionWeights[$region['key']];
            $suggestedDailyBudget = $weightUnits > 0 && $dailyCap > 0
                ? round(($dailyCap * $weight) / $weightUnits, 2)
                : 0.0;

            $adGroups = [];
            foreach ($this->services as $service) {
                foreach ($this->vehicles as $vehicle) {
                    $adGroups[] = $this->buildAdGroup($service, $vehicle, $region, $cities);
                }
            }

            $campaigns[] = [
                'campaign_name' => 'BMT | Search | Region: ' . $region['name'],
                'region_key' => $region['key'],
                'cities' => $cities,
                'suggested_daily_budget' => $suggestedDailyBudget,
                'ad_group_count' => count($adGroups),
                'ad_groups' => $adGroups,
                'recommended_negative_keywords' => array_values(array_unique(array_merge(
                    $this->vehiclePolicy['negative_keyword_candidates'],
                    ['cheap tyres', 'used tyres', 'second hand tyres', 'tyre job', 'tyre jobs',
                     'tyre fitter jobs', 'tyre career', 'tyre training course', 'wholesale tyres',
                     'tyre shop', 'tyre garage near me']
                ))),
            ];
        }

        return ['campaigns' => $campaigns];
    }

    /**
     * Queues one proposal per region not already an existing campaign or an
     * open proposal, so re-running this is always idempotent.
     *
     * @return string[] change_uuids of newly queued proposals
     */
    public function queueStructureProposals(array $existingCampaignNames = []): array
    {
        $plan = $this->build();
        $existing = array_map('mb_strtolower', $existingCampaignNames);

        $queued = [];
        foreach ($plan['campaigns'] as $campaign) {
            $resourceName = $campaign['campaign_name'];

            if (in_array(mb_strtolower($resourceName), $existing, true)) {
                continue;
            }
            if ($this->approvals->hasOpenProposal('google_ads_campaign_structure', $resourceName)) {
                continue;
            }

            $queued[] = $this->approvals->propose([
                'change_type' => 'create_search_structure',
                'resource_type' => 'google_ads_campaign_structure',
                'resource_name' => $resourceName,
                'reason' => 'Programmatic Search structure proposed for ' . $campaign['region_key']
                    . ': ' . $campaign['ad_group_count'] . ' ad groups across '
                    . count($campaign['cities']) . ' cities (5 services x 8 vehicle types).',
                'after_state' => $campaign,
                'risk_level' => 'high',
                'reversible' => true,
            ]);
        }

        return $queued;
    }

    private function regionWeight(array $region): int
    {
        $weight = 0;
        foreach ($region['cities'] as $name) {
            $weight += ($this->cityIndex[$name]['big'] ?? false) ? 2 : 1;
        }
        return max($weight, 1);
    }

    private function resolveCities(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            if (!isset($this->cityIndex[$name])) {
                continue; // config drift between campaign_regions.php and cities.php — skip rather than fail the whole plan
            }
            $city = $this->cityIndex[$name];
            $resolved[] = [
                'name' => $city['name'],
                'lat' => $city['lat'],
                'lng' => $city['lng'],
                'big' => $city['big'],
            ];
        }
        return $resolved;
    }

    /** Truncates to the given length without cutting a word in half, for RSA headline/description limits. */
    private static function truncateWords(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $truncated = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($truncated, ' ');
        return $lastSpace !== false ? mb_substr($truncated, 0, $lastSpace) : $truncated;
    }

    private function buildAdGroup(array $service, array $vehicle, array $region, array $cities): array
    {
        $v = $vehicle['query_term'];
        $terms = $service['query_terms'];
        $primary = $terms[0];
        $secondary = $terms[1] ?? $terms[0];

        // Two representative cities keep location-intent keywords bounded
        // instead of multiplying every ad group by every city in the region.
        $sampleCities = array_slice($cities, 0, 2);
        $locationIntent = [];
        foreach ($sampleCities as $city) {
            $locationIntent[] = "{$v} {$primary} {$city['name']}";
            $locationIntent[] = "{$primary} {$v} {$city['name']}";
        }
        if ($locationIntent === []) {
            $locationIntent[] = "{$v} {$primary} {$region['name']}";
        }

        return [
            'service_slug' => $service['slug'],
            'service_label' => $service['label'],
            'vehicle_slug' => $vehicle['slug'],
            'vehicle_label' => $vehicle['label'],
            'eligibility_type' => $vehicle['eligibility_type'],
            'ad_group_name' => $service['label'] . ' - ' . $vehicle['label'],
            'keywords' => [
                'exact_match' => [
                    "[{$v} {$primary}]",
                    "[{$primary} {$v}]",
                ],
                'phrase_match' => [
                    "\"{$v} {$secondary}\"",
                    "\"{$secondary} for {$v}\"",
                ],
                'near_me' => [
                    "{$v} {$primary} near me",
                    "{$primary} near me {$v}",
                ],
                'location_intent' => $locationIntent,
                'vehicle_service_combo' => [
                    "{$v} {$primary}",
                    "{$primary} for a {$v}",
                ],
            ],
            'responsive_search_ad' => [
                'headlines' => [
                    self::truncateWords($service['label'] . ' - ' . $vehicle['label'], 30),
                    self::truncateWords('We Come To You - ' . $vehicle['label'] . ' Specialists', 30),
                    self::truncateWords(ucfirst($primary) . ' Today', 30),
                    self::truncateWords('Fast Mobile ' . $vehicle['label'] . ' Tyres', 30),
                ],
                'descriptions' => [
                    self::truncateWords('Professional mobile ' . $secondary . ' for your ' . strtolower($vehicle['label']) . ', wherever you are.', 90),
                    self::truncateWords('Same-day ' . $primary . ' across ' . $region['name'] . '. Call or book online now.', 90),
                ],
            ],
        ];
    }
}
