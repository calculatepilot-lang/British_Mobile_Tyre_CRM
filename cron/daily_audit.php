<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Conversions\ConversionPlanService;
use BMT\Database;
use BMT\GoogleAds\AccountAudit;
use BMT\GoogleAds\ReportingService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/London');
$timestamp = date(DATE_ATOM);
echo "[$timestamp] BMT daily audit started.\n";

if ((getenv('AUTOMATION_MODE') ?: 'audit_only') !== 'audit_only') {
    fwrite(STDERR, "Mutation mode is not implemented by this job. Continuing with read-only audit only.\n");
}

try {
    $audit = (new AccountAudit())->run();
    $report = (new ReportingService())->collectYesterday();

    $queuedChangeIds = (new ConversionPlanService(new Database()))
        ->auditAndQueueMissingActions($audit['conversions']);

    $summary = [
        'generated_at' => date(DATE_ATOM),
        'mode' => 'audit_only',
        'campaign_count' => $audit['campaign_count'],
        'conversion_count' => $audit['conversion_count'],
        'metrics_collected' => $report['count'],
        'conversion_proposals_queued' => count($queuedChangeIds),
        'conversion_proposal_ids' => $queuedChangeIds,
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
