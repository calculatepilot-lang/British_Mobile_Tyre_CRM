<?php

declare(strict_types=1);

namespace BMT\Execution;

use BMT\Approvals\ApprovalService;
use BMT\GoogleAds\Client;
use Google\Ads\GoogleAds\V25\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V25\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V25\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V25\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V25\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V25\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V25\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V25\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V25\Enums\ProximityRadiusUnitsEnum\ProximityRadiusUnits;
use Google\Ads\GoogleAds\V25\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V25\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V25\Common\ManualCpc;
use Google\Ads\GoogleAds\V25\Common\ProximityInfo;
use Google\Ads\GoogleAds\V25\Common\GeoPointInfo;
use Google\Ads\GoogleAds\V25\Common\KeywordInfo;
use Google\Ads\GoogleAds\V25\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V25\Common\AdTextAsset;
use Google\Ads\GoogleAds\V25\Resources\ConversionAction;
use Google\Ads\GoogleAds\V25\Resources\Campaign;
use Google\Ads\GoogleAds\V25\Resources\Campaign\NetworkSettings;
use Google\Ads\GoogleAds\V25\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V25\Resources\AdGroup;
use Google\Ads\GoogleAds\V25\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V25\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V25\Resources\Ad;
use Google\Ads\GoogleAds\V25\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V25\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V25\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V25\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V25\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V25\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V25\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V25\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;
use Google\Protobuf\FieldMask;
use RuntimeException;
use Throwable;

/**
 * Applies APPROVED (status='planned') automation_changes proposals to the
 * live Google Ads account. This is the only class in the codebase that
 * performs a write against Google Ads — every other module only proposes.
 *
 * 'create_campaign' creates the campaign SKELETON only — budget, campaign
 * (always PAUSED), one default ad group, and proximity location targeting
 * around the city's planning centre. It deliberately does NOT add keywords,
 * ads, or negative keywords: keyword/ad copy quality is a judgment call a
 * human should make per city, and config/vehicle_policy.php explicitly sets
 * automatic_negative_application=false, so applying negatives automatically
 * here would contradict that policy. A campaign this method creates cannot
 * spend a penny until a human adds ads/keywords AND manually switches it to
 * ENABLED in the Google Ads UI — the PAUSED status is never changed by any
 * code path in this class.
 */
final class ChangeExecutor
{
    private ApprovalService $approvals;

    public function __construct(?ApprovalService $approvals = null)
    {
        $this->approvals = $approvals ?? new ApprovalService();
    }

    /**
     * Executes only approved (status='planned') changes matching the given
     * change_type(s). Used by the scheduled conversion-action executor so
     * low-risk, reversible conversion-action creations can run unattended
     * on a cron once approved — while budget and pause changes stay on the
     * manual "Run approved changes" dashboard button, since those carry
     * real spend risk that deserves a human clicking the button at the
     * moment they want it applied, not just approving it earlier.
     *
     * @param string[] $changeTypes
     * @return array{executed: string[], failed: string[], skipped: string[]}
     */
    public function runPendingByType(array $changeTypes): array
    {
        $result = ['executed' => [], 'failed' => [], 'skipped' => []];

        foreach ($this->approvals->pending() as $change) {
            if (!in_array($change['change_type'], $changeTypes, true)) {
                $result['skipped'][] = $change['change_uuid'];
                continue;
            }

            $uuid = $change['change_uuid'];
            try {
                $before = match ($change['change_type']) {
                    'create_conversion_action' => $this->createConversionAction($change),
                    'create_campaign' => $this->createCampaignSkeleton($change),
                    'create_search_structure' => $this->createSearchStructureSkeleton($change),
                    'increase_budget', 'decrease_budget' => $this->changeBudget($change),
                    'pause_campaign' => $this->pauseCampaign($change),
                    default => null,
                };

                if ($before === null) {
                    $this->approvals->markFailed($uuid, 'Unknown change_type: ' . $change['change_type']);
                    $result['failed'][] = $uuid;
                    continue;
                }

                $this->approvals->markExecuted($uuid, $before['resource_id'] ?? null, $before);
                $result['executed'][] = $uuid;
            } catch (Throwable $e) {
                $this->approvals->markFailed($uuid, $e->getMessage());
                $result['failed'][] = $uuid;
            }
        }

        return $result;
    }

    /**
     * Executes every approved (status='planned') change. Each change is
     * handled independently — one failure never blocks the rest, and every
     * outcome (success or failure) is recorded on the change itself.
     *
     * @return array{executed: string[], failed: string[], skipped: string[]}
     */
    public function runPending(): array
    {
        $result = ['executed' => [], 'failed' => [], 'skipped' => []];

        foreach ($this->approvals->pending() as $change) {
            $uuid = $change['change_uuid'];

            try {
                $before = match ($change['change_type']) {
                    'create_conversion_action' => $this->createConversionAction($change),
                    'create_campaign' => $this->createCampaignSkeleton($change),
                    'create_search_structure' => $this->createSearchStructureSkeleton($change),
                    'increase_budget', 'decrease_budget' => $this->changeBudget($change),
                    'pause_campaign' => $this->pauseCampaign($change),
                    default => null,
                };

                if ($before === null) {
                    $this->approvals->markFailed($uuid, 'Unknown change_type: ' . $change['change_type']);
                    $result['failed'][] = $uuid;
                    continue;
                }

                $this->approvals->markExecuted($uuid, $before['resource_id'] ?? null, $before);
                $result['executed'][] = $uuid;
            } catch (Throwable $e) {
                $this->approvals->markFailed($uuid, $e->getMessage());
                $result['failed'][] = $uuid;
            }
        }

        return $result;
    }

    private function createConversionAction(array $change): array
    {
        $after = json_decode((string) $change['after_state'], true, 512, JSON_THROW_ON_ERROR);
        $client = Client::make();
        $customerId = Client::customerId();
        $service = $client->getConversionActionServiceClient();

        $action = new ConversionAction([
            'name' => (string) ($after['name'] ?? $change['resource_name']),
            'category' => ConversionActionCategory::value((string) ($after['category'] ?? 'DEFAULT')),
            'type' => ConversionActionType::value((string) ($after['type'] ?? 'WEBPAGE')),
            'status' => ConversionActionStatus::value('ENABLED'),
        ]);

        $operation = new ConversionActionOperation();
        $operation->setCreate($action);

        $response = $service->mutateConversionActions(new MutateConversionActionsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$operation],
        ]));

        $resourceName = $response->getResults()[0]->getResourceName();

        return ['resource_id' => $resourceName, 'action' => 'created', 'previous_state' => null];
    }

    /**
     * Creates a Search campaign SKELETON from a CampaignPlanner proposal:
     * a budget, the campaign itself (always PAUSED — see class docblock),
     * one default ad group, and proximity-based location targeting around
     * the city's planning centre. No keywords, ads, or negative keywords
     * are added — a human finishes those in the Google Ads UI and switches
     * the campaign to ENABLED only once they're satisfied with it.
     *
     * Proximity radius is a flat 15km around the city centre point rather
     * than any invented road/motorway geometry — config/cities.php is
     * explicit that road corridors must be independently verified before
     * use, and this method has no way to verify them, so it doesn't attempt to.
     */
    private function createCampaignSkeleton(array $change): array
    {
        $after = json_decode((string) $change['after_state'], true, 512, JSON_THROW_ON_ERROR);
        $client = Client::make();
        $customerId = Client::customerId();

        $campaignName = (string) ($after['campaign_name'] ?? $change['resource_name']);
        $dailyBudget = (float) ($after['suggested_daily_budget'] ?? 0);
        if ($dailyBudget <= 0) {
            throw new RuntimeException('Cannot create campaign "' . $campaignName . '" — no positive suggested_daily_budget on the proposal.');
        }
        $lat = $after['planning_centre']['lat'] ?? null;
        $lng = $after['planning_centre']['lng'] ?? null;
        if ($lat === null || $lng === null) {
            throw new RuntimeException('Cannot create campaign "' . $campaignName . '" — proposal is missing a planning_centre lat/lng.');
        }

        // 1. Budget — never shared, standard delivery, amount in the
        // account's own currency (PKR), matching how suggested_daily_budget
        // was computed by CampaignPlanner.
        $budgetService = $client->getCampaignBudgetServiceClient();
        $budget = new CampaignBudget([
            'name' => $campaignName . ' - Budget - ' . bin2hex(random_bytes(4)),
            'amount_micros' => (int) round($dailyBudget * 1_000_000),
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => false,
        ]);
        $budgetOperation = new CampaignBudgetOperation();
        $budgetOperation->setCreate($budget);
        $budgetResponse = $budgetService->mutateCampaignBudgets(new MutateCampaignBudgetsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$budgetOperation],
        ]));
        $budgetResourceName = $budgetResponse->getResults()[0]->getResourceName();

        // 2. Campaign — always PAUSED. Manual CPC to match this account's
        // existing bidding approach (see /areas notes: Manual CPC is the
        // primary acquisition channel). Search-only network settings —
        // never the wider Display/Search-partner network by default.
        $campaignService = $client->getCampaignServiceClient();
        $campaign = new Campaign([
            'name' => $campaignName,
            'status' => CampaignStatus::PAUSED,
            'advertising_channel_type' => AdvertisingChannelType::SEARCH,
            'campaign_budget' => $budgetResourceName,
            'manual_cpc' => new ManualCpc(),
            'network_settings' => new NetworkSettings([
                'target_google_search' => true,
                'target_search_network' => false,
                'target_content_network' => false,
                'target_partner_search_network' => false,
            ]),
        ]);
        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);
        $campaignResponse = $campaignService->mutateCampaigns(new MutateCampaignsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$campaignOperation],
        ]));
        $campaignResourceName = $campaignResponse->getResults()[0]->getResourceName();

        // 3. One default ad group — empty of keywords/ads. Human adds those.
        $adGroupService = $client->getAdGroupServiceClient();
        $adGroup = new AdGroup([
            'name' => $campaignName . ' - General',
            'campaign' => $campaignResourceName,
            'status' => AdGroupStatus::ENABLED, // harmless while the campaign itself is PAUSED
            'type' => AdGroupType::SEARCH_STANDARD,
        ]);
        $adGroupOperation = new AdGroupOperation();
        $adGroupOperation->setCreate($adGroup);
        $adGroupResponse = $adGroupService->mutateAdGroups(new MutateAdGroupsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$adGroupOperation],
        ]));
        $adGroupResourceName = $adGroupResponse->getResults()[0]->getResourceName();

        // 4. Proximity location targeting — campaign-level criterion, flat
        // 15km radius around the verified city centre point.
        $criterionService = $client->getCampaignCriterionServiceClient();
        $criterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
            'proximity' => new ProximityInfo([
                'geo_point' => new GeoPointInfo([
                    'latitude_in_micro_degrees' => (int) round(((float) $lat) * 1_000_000),
                    'longitude_in_micro_degrees' => (int) round(((float) $lng) * 1_000_000),
                ]),
                'radius' => 15,
                'radius_units' => ProximityRadiusUnits::KILOMETERS,
            ]),
        ]);
        $criterionOperation = new CampaignCriterionOperation();
        $criterionOperation->setCreate($criterion);
        $criterionService->mutateCampaignCriteria(new MutateCampaignCriteriaRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$criterionOperation],
        ]));

        return [
            'resource_id' => $campaignResourceName,
            'action' => 'campaign_skeleton_created',
            'previous_state' => null,
            'created' => [
                'campaign' => $campaignResourceName,
                'budget' => $budgetResourceName,
                'ad_group' => $adGroupResourceName,
                'status' => 'PAUSED',
            ],
            'still_needed_before_enabling' => [
                'Add keywords to the ad group.',
                'Add at least one responsive search ad.',
                'Review and add negative keywords (see config/vehicle_policy.php negative_keyword_candidates — not applied automatically).',
                'Switch campaign status to ENABLED in the Google Ads UI once satisfied.',
            ],
        ];
    }

    /**
     * Creates a full programmatic Search structure from a
     * SearchStructurePlanner proposal: a budget, the campaign (always
     * PAUSED), proximity location targeting around every city in the
     * region, all 40 ad groups, every generated keyword (exact/phrase/
     * near-me/location-intent/vehicle+service — each assigned an actual
     * Google Ads match type: EXACT for exact/near-me/vehicle+service
     * combos, PHRASE for phrase-match and location-intent variants), and
     * one responsive search ad per ad group.
     *
     * Deliberately does NOT add negative keywords, for the same reason
     * createCampaignSkeleton() doesn't: config/vehicle_policy.php sets
     * automatic_negative_application=false, and applying them here would
     * contradict that policy even though the campaign stays paused. The
     * proposal's recommended_negative_keywords are surfaced instead as a
     * post-creation checklist item, same pattern as the checklist already
     * shown on /changes for create_campaign.
     *
     * Mutate calls are chunked to keep each request comfortably under the
     * Google Ads API's per-request operation limits and avoid PHP request
     * timeouts — run this from cron/CLI rather than expecting it to finish
     * inside a single web request.
     */
    private function createSearchStructureSkeleton(array $change): array
    {
        $after = json_decode((string) $change['after_state'], true, 512, JSON_THROW_ON_ERROR);
        $client = Client::make();
        $customerId = Client::customerId();

        $campaignName = (string) ($after['campaign_name'] ?? $change['resource_name']);
        $dailyBudget = (float) ($after['suggested_daily_budget'] ?? 0);
        if ($dailyBudget <= 0) {
            throw new RuntimeException('Cannot create Search structure "' . $campaignName . '" — no positive suggested_daily_budget on the proposal.');
        }
        $cities = $after['cities'] ?? [];
        if ($cities === []) {
            throw new RuntimeException('Cannot create Search structure "' . $campaignName . '" — proposal has no cities.');
        }
        $adGroupPlans = $after['ad_groups'] ?? [];
        if ($adGroupPlans === []) {
            throw new RuntimeException('Cannot create Search structure "' . $campaignName . '" — proposal has no ad groups.');
        }

        // 1. Budget.
        $budgetService = $client->getCampaignBudgetServiceClient();
        $budget = new CampaignBudget([
            'name' => $campaignName . ' - Budget - ' . bin2hex(random_bytes(4)),
            'amount_micros' => (int) round($dailyBudget * 1_000_000),
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => false,
        ]);
        $budgetOperation = new CampaignBudgetOperation();
        $budgetOperation->setCreate($budget);
        $budgetResponse = $budgetService->mutateCampaignBudgets(new MutateCampaignBudgetsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$budgetOperation],
        ]));
        $budgetResourceName = $budgetResponse->getResults()[0]->getResourceName();

        // 2. Campaign — always PAUSED, Manual CPC, Search network only.
        $campaignService = $client->getCampaignServiceClient();
        $campaign = new Campaign([
            'name' => $campaignName,
            'status' => CampaignStatus::PAUSED,
            'advertising_channel_type' => AdvertisingChannelType::SEARCH,
            'campaign_budget' => $budgetResourceName,
            'manual_cpc' => new ManualCpc(),
            'network_settings' => new NetworkSettings([
                'target_google_search' => true,
                'target_search_network' => false,
                'target_content_network' => false,
                'target_partner_search_network' => false,
            ]),
        ]);
        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);
        $campaignResponse = $campaignService->mutateCampaigns(new MutateCampaignsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$campaignOperation],
        ]));
        $campaignResourceName = $campaignResponse->getResults()[0]->getResourceName();

        // 3. One proximity criterion (15km) per city in the region.
        $criterionOperations = [];
        foreach ($cities as $city) {
            if (!isset($city['lat'], $city['lng'])) {
                continue;
            }
            $criterion = new CampaignCriterion([
                'campaign' => $campaignResourceName,
                'proximity' => new ProximityInfo([
                    'geo_point' => new GeoPointInfo([
                        'latitude_in_micro_degrees' => (int) round(((float) $city['lat']) * 1_000_000),
                        'longitude_in_micro_degrees' => (int) round(((float) $city['lng']) * 1_000_000),
                    ]),
                    'radius' => 15,
                    'radius_units' => ProximityRadiusUnits::KILOMETERS,
                ]),
            ]);
            $operation = new CampaignCriterionOperation();
            $operation->setCreate($criterion);
            $criterionOperations[] = $operation;
        }
        $this->mutateInChunks(
            $client->getCampaignCriterionServiceClient(),
            'mutateCampaignCriteria',
            MutateCampaignCriteriaRequest::class,
            (string) $customerId,
            $criterionOperations
        );

        // 4. Ad groups — one per service x vehicle combo, ENABLED (harmless
        // while the parent campaign is PAUSED).
        $adGroupService = $client->getAdGroupServiceClient();
        $adGroupOperations = [];
        foreach ($adGroupPlans as $plan) {
            $adGroup = new AdGroup([
                'name' => (string) $plan['ad_group_name'],
                'campaign' => $campaignResourceName,
                'status' => AdGroupStatus::ENABLED,
                'type' => AdGroupType::SEARCH_STANDARD,
            ]);
            $operation = new AdGroupOperation();
            $operation->setCreate($adGroup);
            $adGroupOperations[] = $operation;
        }
        $adGroupResponses = $this->mutateInChunks(
            $adGroupService,
            'mutateAdGroups',
            MutateAdGroupsRequest::class,
            (string) $customerId,
            $adGroupOperations
        );
        $adGroupResourceNames = [];
        foreach ($adGroupResponses as $result) {
            $adGroupResourceNames[] = $result->getResourceName();
        }

        // 5. Keywords per ad group — exact/near-me/vehicle+service combos as
        // EXACT match, phrase/location-intent variants as PHRASE match.
        $keywordOperations = [];
        $adCount = 0;
        $adGroupAdOperations = [];
        foreach ($adGroupPlans as $index => $plan) {
            $adGroupResourceName = $adGroupResourceNames[$index] ?? null;
            if ($adGroupResourceName === null) {
                continue;
            }

            $matchTypeGroups = [
                KeywordMatchType::EXACT => array_merge(
                    $plan['keywords']['exact_match'] ?? [],
                    $plan['keywords']['near_me'] ?? [],
                    $plan['keywords']['vehicle_service_combo'] ?? []
                ),
                KeywordMatchType::PHRASE => array_merge(
                    $plan['keywords']['phrase_match'] ?? [],
                    $plan['keywords']['location_intent'] ?? []
                ),
            ];
            foreach ($matchTypeGroups as $matchType => $texts) {
                foreach (array_unique($texts) as $text) {
                    // Keyword text stored includes literal [] / "" wrappers
                    // from the planner for readability on /changes — strip
                    // them here since match_type is what actually sets it.
                    $clean = trim((string) $text, "[]\" ");
                    if ($clean === '') {
                        continue;
                    }
                    $criterion = new AdGroupCriterion([
                        'ad_group' => $adGroupResourceName,
                        'keyword' => new KeywordInfo([
                            'text' => $clean,
                            'match_type' => $matchType,
                        ]),
                    ]);
                    $operation = new AdGroupCriterionOperation();
                    $operation->setCreate($criterion);
                    $keywordOperations[] = $operation;
                }
            }

            // 6. One responsive search ad per ad group.
            $rsa = $plan['responsive_search_ad'] ?? null;
            if ($rsa && !empty($rsa['headlines']) && !empty($rsa['descriptions'])) {
                $headlineAssets = array_map(
                    static fn (string $h): AdTextAsset => new AdTextAsset(['text' => $h]),
                    array_slice(array_values(array_filter($rsa['headlines'])), 0, 15)
                );
                $descriptionAssets = array_map(
                    static fn (string $d): AdTextAsset => new AdTextAsset(['text' => $d]),
                    array_slice(array_values(array_filter($rsa['descriptions'])), 0, 4)
                );
                if (count($headlineAssets) >= 3 && count($descriptionAssets) >= 2) {
                    $adGroupAd = new AdGroupAd([
                        'ad_group' => $adGroupResourceName,
                        'status' => AdGroupAdStatus::ENABLED,
                        'ad' => new Ad([
                            'responsive_search_ad' => new ResponsiveSearchAdInfo([
                                'headlines' => $headlineAssets,
                                'descriptions' => $descriptionAssets,
                            ]),
                        ]),
                    ]);
                    $operation = new AdGroupAdOperation();
                    $operation->setCreate($adGroupAd);
                    $adGroupAdOperations[] = $operation;
                    $adCount++;
                }
            }
        }

        $this->mutateInChunks(
            $client->getAdGroupCriterionServiceClient(),
            'mutateAdGroupCriteria',
            MutateAdGroupCriteriaRequest::class,
            (string) $customerId,
            $keywordOperations
        );
        $this->mutateInChunks(
            $client->getAdGroupAdServiceClient(),
            'mutateAdGroupAds',
            MutateAdGroupAdsRequest::class,
            (string) $customerId,
            $adGroupAdOperations
        );

        return [
            'resource_id' => $campaignResourceName,
            'action' => 'search_structure_created',
            'previous_state' => null,
            'created' => [
                'campaign' => $campaignResourceName,
                'budget' => $budgetResourceName,
                'ad_groups' => count($adGroupResourceNames),
                'keywords' => count($keywordOperations),
                'ads' => $adCount,
                'status' => 'PAUSED',
            ],
            'still_needed_before_enabling' => [
                'Review and add negative keywords — see recommended_negative_keywords on this proposal (config/vehicle_policy.php negative_keyword_candidates plus common irrelevant-traffic terms). Not applied automatically.',
                'Review generated keyword text and responsive search ad copy for accuracy per ad group.',
                'Confirm bids/budget are appropriate before enabling.',
                'Switch campaign status to ENABLED in the Google Ads UI once satisfied.',
            ],
        ];
    }

    /**
     * Splits a large operation list into chunks of 1000 (well under the
     * Google Ads API's per-request operation ceiling) so a 400-ad-group
     * structure's several thousand keyword/ad operations don't risk a
     * single oversized request timing out or being rejected outright.
     * Returns the concatenated MutateOperationResponse results in order.
     */
    private function mutateInChunks(object $service, string $method, string $requestClass, string $customerId, array $operations): array
    {
        $results = [];
        foreach (array_chunk($operations, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $request = new $requestClass([
                'customer_id' => $customerId,
                'operations' => $chunk,
            ]);
            $response = $service->{$method}($request);
            foreach ($response->getResults() as $result) {
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * Looks up the campaign's current budget fresh at execution time (never
     * trusts the amount captured when the proposal was queued, which may be
     * stale) and applies the configured percent change from after_state.
     */
    private function changeBudget(array $change): array
    {
        $after = json_decode((string) $change['after_state'], true, 512, JSON_THROW_ON_ERROR);
        $percent = (float) ($after['proposed_change_percent'] ?? 0);

        $client = Client::make();
        $customerId = Client::customerId();
        $gaService = $client->getGoogleAdsServiceClient();

        $rows = $gaService->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => sprintf(
                "SELECT campaign_budget.resource_name, campaign_budget.amount_micros FROM campaign WHERE campaign.name = '%s' LIMIT 1",
                str_replace("'", "\\'", (string) $change['resource_name'])
            ),
        ]));

        $budgetResourceName = null;
        $currentMicros = null;
        foreach ($rows->iterateAllElements() as $row) {
            $budgetResourceName = $row->getCampaignBudget()->getResourceName();
            $currentMicros = (int) $row->getCampaignBudget()->getAmountMicros();
        }

        if ($budgetResourceName === null || $currentMicros === null) {
            throw new RuntimeException('Campaign "' . $change['resource_name'] . '" or its budget could not be found — it may have been renamed or removed since this change was proposed.');
        }

        $newMicros = (int) round($currentMicros * (1 + $percent / 100));
        // Floor at 500 (account currency — PKR for this account) so a stray
        // -100% proposal can never zero a live budget out entirely.
        $newMicros = max($newMicros, 500_000_000);

        $budget = new CampaignBudget([
            'resource_name' => $budgetResourceName,
            'amount_micros' => $newMicros,
        ]);

        $operation = new CampaignBudgetOperation();
        $operation->setUpdate($budget);
        $operation->setUpdateMask(new FieldMask(['paths' => ['amount_micros']]));

        $client->getCampaignBudgetServiceClient()->mutateCampaignBudgets(new MutateCampaignBudgetsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$operation],
        ]));

        return [
            'resource_id' => $budgetResourceName,
            'action' => 'budget_updated',
            'previous_state' => ['amount_micros' => $currentMicros],
            'new_state' => ['amount_micros' => $newMicros],
        ];
    }

    private function pauseCampaign(array $change): array
    {
        $client = Client::make();
        $customerId = Client::customerId();
        $gaService = $client->getGoogleAdsServiceClient();

        $rows = $gaService->search(new SearchGoogleAdsRequest([
            'customer_id' => (string) $customerId,
            'query' => sprintf(
                "SELECT campaign.resource_name, campaign.status FROM campaign WHERE campaign.name = '%s' LIMIT 1",
                str_replace("'", "\\'", (string) $change['resource_name'])
            ),
        ]));

        $campaignResourceName = null;
        $previousStatus = null;
        foreach ($rows->iterateAllElements() as $row) {
            $campaignResourceName = $row->getCampaign()->getResourceName();
            $previousStatus = (string) $row->getCampaign()->getStatus();
        }

        if ($campaignResourceName === null) {
            throw new RuntimeException('Campaign "' . $change['resource_name'] . '" could not be found — it may have been renamed or removed since this change was proposed.');
        }

        $campaign = new Campaign([
            'resource_name' => $campaignResourceName,
            'status' => CampaignStatus::value('PAUSED'),
        ]);

        $operation = new CampaignOperation();
        $operation->setUpdate($campaign);
        $operation->setUpdateMask(new FieldMask(['paths' => ['status']]));

        $client->getCampaignServiceClient()->mutateCampaigns(new MutateCampaignsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$operation],
        ]));

        return [
            'resource_id' => $campaignResourceName,
            'action' => 'paused',
            'previous_state' => ['status' => $previousStatus],
        ];
    }
}
