<?php

declare(strict_types=1);

namespace BMT\Execution;

use BMT\Approvals\ApprovalService;
use BMT\GoogleAds\Client;
use Google\Ads\GoogleAds\V25\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V25\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V25\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V25\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V25\Resources\ConversionAction;
use Google\Ads\GoogleAds\V25\Resources\Campaign;
use Google\Ads\GoogleAds\V25\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V25\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignOperation;
use Google\Ads\GoogleAds\V25\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V25\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V25\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;
use Google\Protobuf\FieldMask;
use RuntimeException;
use Throwable;

/**
 * Applies APPROVED (status='planned') automation_changes proposals to the
 * live Google Ads account. This is the only class in the codebase that
 * performs a write against Google Ads — every other module only proposes.
 *
 * Deliberately does NOT implement 'create_campaign'. Creating a Search
 * campaign well (ad groups, keywords, ads, location targets, negative
 * keywords, network settings) is a materially larger and higher-risk
 * operation than the single-resource mutations below, and a misconfigured
 * auto-created campaign can waste real budget before anyone notices.
 * campaign proposals are surfaced on /changes for manual creation using
 * their after_state as a spec, until a reviewed campaign-creation flow is
 * built and tested against a real account.
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
                    'increase_budget', 'decrease_budget' => $this->changeBudget($change),
                    'pause_campaign' => $this->pauseCampaign($change),
                    default => null,
                };

                if ($before === null && $change['change_type'] === 'create_campaign') {
                    $result['skipped'][] = $uuid;
                    continue;
                }

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
