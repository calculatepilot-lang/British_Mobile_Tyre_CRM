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
use Google\Ads\GoogleAds\V25\Common\ManualCpc;
use Google\Ads\GoogleAds\V25\Common\ProximityInfo;
use Google\Ads\GoogleAds\V25\Common\GeoPointInfo;
use Google\Ads\GoogleAds\V25\Resources\ConversionAction;
use Google\Ads\GoogleAds\V25\Resources\Campaign;
use Google\Ads\GoogleAds\V25\Resources\Campaign\NetworkSettings;
use Google\Ads\GoogleAds\V25\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V25\Resources\AdGroup;
use Google\Ads\GoogleAds\V25\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V25\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V25\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V25\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V25\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V25\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V25\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V25\Common\KeywordInfo;
use Google\Ads\GoogleAds\V25\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V25\Common\AdTextAsset;
use Google\Ads\GoogleAds\V25\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V25\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V25\Resources\Ad;
use Google\Ads\GoogleAds\V25\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V25\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V25\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V25\Services\MutateAdGroupAdsRequest;
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
                    'create_service_campaign' => $this->createServiceCampaign($change),
                    'add_ad_group_content' => $this->addAdGroupContent($change),
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
                    'create_service_campaign' => $this->createServiceCampaign($change),
                    'add_ad_group_content' => $this->addAdGroupContent($change),
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
     * Executes a queueServiceCampaignProposals() proposal: one campaign,
     * proximity location targeting for every city on the proposal, and
     * ALL 8 vehicle ad groups (with their keywords and one RSA each) in a
     * single pass. The campaign is always created PAUSED — see class
     * docblock — and stays that way regardless of how much content is
     * added; only a human switches it to ENABLED in the Google Ads UI.
     */
    private function createServiceCampaign(array $change): array
    {
        $after = json_decode((string) $change['after_state'], true, 512, JSON_THROW_ON_ERROR);
        $client = Client::make();
        $customerId = Client::customerId();

        $campaignName = (string) ($after['campaign_name'] ?? $change['resource_name']);
        $dailyBudget = (float) ($after['suggested_daily_budget'] ?? 0);
        $cityCentres = $after['city_centres'] ?? [];
        $adGroupSpecs = $after['ad_groups'] ?? [];
        $finalUrl = (string) ($after['final_url'] ?? '');

        if ($dailyBudget <= 0) {
            throw new RuntimeException('Cannot create campaign "' . $campaignName . '" — no positive suggested_daily_budget.');
        }
        if ($cityCentres === []) {
            throw new RuntimeException('Cannot create campaign "' . $campaignName . '" — no city_centres on the proposal.');
        }
        if ($adGroupSpecs === [] || $finalUrl === '') {
            throw new RuntimeException('Cannot create campaign "' . $campaignName . '" — missing ad_groups or final_url.');
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

        // 2. Campaign — always PAUSED, Search only, Manual CPC.
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

        // 3. Proximity location targeting — one 15km-radius criterion per
        // city, so all 61 cities are targeted on every service campaign.
        $criterionService = $client->getCampaignCriterionServiceClient();
        $locationOperations = [];
        foreach ($cityCentres as $city) {
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
            $locationOperations[] = $operation;
        }
        $criterionService->mutateCampaignCriteria(new MutateCampaignCriteriaRequest([
            'customer_id' => (string) $customerId,
            'operations' => $locationOperations,
        ]));

        // 4. All 8 ad groups in one batch.
        $adGroupService = $client->getAdGroupServiceClient();
        $adGroupOperations = [];
        foreach ($adGroupSpecs as $spec) {
            $adGroup = new AdGroup([
                'name' => (string) $spec['ad_group_name'],
                'campaign' => $campaignResourceName,
                'status' => AdGroupStatus::ENABLED, // harmless while the campaign itself is PAUSED
                'type' => AdGroupType::SEARCH_STANDARD,
            ]);
            $operation = new AdGroupOperation();
            $operation->setCreate($adGroup);
            $adGroupOperations[] = $operation;
        }
        $adGroupResponse = $adGroupService->mutateAdGroups(new MutateAdGroupsRequest([
            'customer_id' => (string) $customerId,
            'operations' => $adGroupOperations,
        ]));
        $adGroupResourceNames = [];
        foreach ($adGroupResponse->getResults() as $i => $result) {
            $adGroupResourceNames[$i] = $result->getResourceName();
        }

        // 5. Keywords for every ad group, in one batch.
        $criterionOperations = [];
        foreach ($adGroupSpecs as $i => $spec) {
            foreach ($spec['keywords'] ?? [] as $keyword) {
                $text = (string) ($keyword['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $criterion = new AdGroupCriterion([
                    'ad_group' => $adGroupResourceNames[$i],
                    'status' => AdGroupCriterionStatus::ENABLED,
                    'keyword' => new KeywordInfo([
                        'text' => $text,
                        'match_type' => KeywordMatchType::value((string) ($keyword['match_type'] ?? 'PHRASE')),
                    ]),
                ]);
                $operation = new AdGroupCriterionOperation();
                $operation->setCreate($criterion);
                $criterionOperations[] = $operation;
            }
        }
        if ($criterionOperations !== []) {
            $client->getAdGroupCriterionServiceClient()->mutateAdGroupCriteria(new MutateAdGroupCriteriaRequest([
                'customer_id' => (string) $customerId,
                'operations' => $criterionOperations,
            ]));
        }

        // 6. One RSA per ad group, in one batch.
        $adOperations = [];
        foreach ($adGroupSpecs as $i => $spec) {
            $headlines = $spec['headlines'] ?? [];
            $descriptions = $spec['descriptions'] ?? [];
            if (count($headlines) < 3 || count($descriptions) < 2) {
                continue; // not enough surviving templates for this vehicle/service pair — skip, don't fail the whole campaign
            }
            $ad = new Ad([
                'final_urls' => [$finalUrl],
                'responsive_search_ad' => new ResponsiveSearchAdInfo([
                    'headlines' => array_map(fn (string $h): AdTextAsset => new AdTextAsset(['text' => $h]), $headlines),
                    'descriptions' => array_map(fn (string $d): AdTextAsset => new AdTextAsset(['text' => $d]), $descriptions),
                ]),
            ]);
            $adGroupAd = new AdGroupAd([
                'ad_group' => $adGroupResourceNames[$i],
                'status' => AdGroupAdStatus::ENABLED,
                'ad' => $ad,
            ]);
            $operation = new AdGroupAdOperation();
            $operation->setCreate($adGroupAd);
            $adOperations[] = $operation;
        }
        if ($adOperations !== []) {
            $client->getAdGroupAdServiceClient()->mutateAdGroupAds(new MutateAdGroupAdsRequest([
                'customer_id' => (string) $customerId,
                'operations' => $adOperations,
            ]));
        }

        return [
            'resource_id' => $campaignResourceName,
            'action' => 'service_campaign_created',
            'previous_state' => null,
            'created' => [
                'campaign' => $campaignResourceName,
                'budget' => $budgetResourceName,
                'ad_groups' => $adGroupResourceNames,
                'cities_targeted' => count($cityCentres),
                'ads_created' => count($adOperations),
                'status' => 'PAUSED',
            ],
            'still_needed_before_enabling' => [
                'Review and add negative keywords (see negative_keywords_suggested on this proposal — not applied automatically).',
                'Spot-check a few ad groups\' keywords and ad copy before enabling.',
                'Switch campaign status to ENABLED in the Google Ads UI once satisfied.',
            ],
        ];
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
     * Adds keywords (Phrase match) and one responsive search ad to an
     * existing ad group, from a CampaignPlanner::queueAdGroupContentProposals
     * proposal. The ad group and its campaign are left exactly as they were
     * — this never changes a PAUSED campaign to ENABLED. Negative keywords
     * are listed on the proposal for a human to review but are never
     * applied here, matching config/vehicle_policy.php's
     * automatic_negative_application=false.
     */
    private function addAdGroupContent(array $change): array
    {
        $after = json_decode((string) $change['after_state'], true, 512, JSON_THROW_ON_ERROR);
        $client = Client::make();
        $customerId = Client::customerId();

        $adGroupResourceName = (string) ($after['ad_group_resource_name'] ?? '');
        $keywords = $after['keywords'] ?? [];
        $headlines = $after['headlines'] ?? [];
        $descriptions = $after['descriptions'] ?? [];
        $finalUrl = (string) ($after['final_url'] ?? '');

        if ($adGroupResourceName === '' || $finalUrl === '') {
            throw new RuntimeException('Proposal is missing ad_group_resource_name or final_url.');
        }
        if (count($headlines) < 3 || count($descriptions) < 2) {
            throw new RuntimeException('Responsive search ads need at least 3 headlines and 2 descriptions.');
        }

        // 1. Keywords — Phrase match, one operation per keyword.
        $criterionOperations = [];
        foreach ($keywords as $keyword) {
            $text = (string) ($keyword['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $criterion = new AdGroupCriterion([
                'ad_group' => $adGroupResourceName,
                'status' => AdGroupCriterionStatus::ENABLED,
                'keyword' => new KeywordInfo([
                    'text' => $text,
                    'match_type' => KeywordMatchType::value((string) ($keyword['match_type'] ?? 'PHRASE')),
                ]),
            ]);
            $operation = new AdGroupCriterionOperation();
            $operation->setCreate($criterion);
            $criterionOperations[] = $operation;
        }

        $keywordResourceNames = [];
        if ($criterionOperations !== []) {
            $criterionService = $client->getAdGroupCriterionServiceClient();
            $criterionResponse = $criterionService->mutateAdGroupCriteria(new MutateAdGroupCriteriaRequest([
                'customer_id' => (string) $customerId,
                'operations' => $criterionOperations,
            ]));
            foreach ($criterionResponse->getResults() as $result) {
                $keywordResourceNames[] = $result->getResourceName();
            }
        }

        // 2. One responsive search ad using every headline/description
        // supplied — Google Ads itself decides which combinations to serve.
        $headlineAssets = array_map(fn (string $h): AdTextAsset => new AdTextAsset(['text' => $h]), $headlines);
        $descriptionAssets = array_map(fn (string $d): AdTextAsset => new AdTextAsset(['text' => $d]), $descriptions);

        $ad = new Ad([
            'final_urls' => [$finalUrl],
            'responsive_search_ad' => new ResponsiveSearchAdInfo([
                'headlines' => $headlineAssets,
                'descriptions' => $descriptionAssets,
            ]),
        ]);
        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status' => AdGroupAdStatus::ENABLED, // harmless while the campaign itself is PAUSED
            'ad' => $ad,
        ]);
        $adOperation = new AdGroupAdOperation();
        $adOperation->setCreate($adGroupAd);
        $adService = $client->getAdGroupAdServiceClient();
        $adResponse = $adService->mutateAdGroupAds(new MutateAdGroupAdsRequest([
            'customer_id' => (string) $customerId,
            'operations' => [$adOperation],
        ]));
        $adResourceName = $adResponse->getResults()[0]->getResourceName();

        return [
            'resource_id' => $adResourceName,
            'action' => 'ad_group_content_created',
            'previous_state' => null,
            'created' => [
                'ad_group' => $adGroupResourceName,
                'keywords' => $keywordResourceNames,
                'ad' => $adResourceName,
            ],
            'still_needed_before_enabling' => [
                'Review and add negative keywords (see negative_keywords_suggested on this proposal — not applied automatically).',
                'Switch campaign status to ENABLED in the Google Ads UI once satisfied.',
            ],
        ];
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
