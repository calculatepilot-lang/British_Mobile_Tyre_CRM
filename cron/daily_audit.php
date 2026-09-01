<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Campaigns\CampaignPlanner;
use BMT\Campaigns\SearchStructurePlanner;
use BMT\Conversions\ConversionPlanService;
use BMT\Database;
use BMT\GoogleAds\AccountAudit;
use BMT\GoogleAds\ReportingService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London');
$timestamp = date(DATE_ATOM);
echo "[$timestamp] BMT daily audit started.\n";

if ((($_ENV['AUTOMATION_MODE'] ?? $_SERVER['AUTOMATION_MODE'] ?? getenv('AUTOMATION_MODE') ?: null) ?: 'audit_only') !== 'audit_only') {
    fwrite(STDERR, "This job only queues proposals — it never mutates Google Ads itself. Live execution (once approved) happens via /changes or cron/execute_conversion_actions.php.\n");
}

try {
    $audit = (new AccountAudit())->run();
    $report = (new ReportingService())->collectYesterday();

    $queuedChangeIds = (new ConversionPlanService(new Database()))
        ->auditAndQueueMissingActions($audit['conversions']);

    $existingCampaignNames = array_map(static fn(array $c): string => (string) $c['name'], $audit['campaigns']);
    $queuedCampaignIds = (new CampaignPlanner())->queueCampaignProposals($existingCampaignNames);

    // Idempotent like the per-city planner above (checks existing campaign
    // names + open proposals per region), so it's safe to include in every
    // daily run even though the 10-region structure is really a one-time
    // build — it will only ever queue the same 10 proposals once.
    $queuedStructureIds = (new SearchStructurePlanner())->queueStructureProposals($existingCampaignNames);

    $summary = [
        'generated_at' => date(DATE_ATOM),
        'mode' => 'audit_only',
        'campaign_count' => $audit['campaign_count'],
        'conversion_count' => $audit['conversion_count'],
        'metrics_collected' => $report['count'],
        'conversion_proposals_queued' => count($queuedChangeIds),
        'conversion_proposal_ids' => $queuedChangeIds,
        'campaign_proposals_queued' => count($queuedCampaignIds),
        'campaign_proposal_ids' => $queuedCampaignIds,
        'search_structure_proposals_queued' => count($queuedStructureIds),
        'search_structure_proposal_ids' => $queuedStructureIds,
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] Daily audit failed: ' . $e->getMessage() . PHP_EOL);

    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
        );
        $stmt->execute([
            'context' => 'daily_audit',
            'message' => $e->getMessage(),
            'payload' => json_encode(['trace' => $e->getTraceAsString()], JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable) {
        // Best-effort logging only.
    }

    exit(1);
}
