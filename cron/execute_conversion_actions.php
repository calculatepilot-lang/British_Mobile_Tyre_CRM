<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Database;
use BMT\Execution\ChangeExecutor;

/**
 * Scheduled executor for APPROVED conversion-action creations only.
 *
 * The daily audit (cron/daily_audit.php) detects missing conversion actions
 * and queues them for human approval on /changes — that step is unchanged
 * and still required. This job runs AFTER approval: it picks up whatever
 * conversion-action proposals have been approved (status='planned') since
 * it last ran and creates them in Google Ads, without needing someone to
 * click "Run approved changes" on the dashboard.
 *
 * Deliberately scoped to ONLY 'create_conversion_action' via runPendingByType()
 * — budget and pause changes are real-spend-risk operations that stay on the
 * manual dashboard button, where a human chooses the moment they take effect.
 * A new conversion action, by contrast, does nothing to spend or targeting
 * until it's actually attached to a campaign as a goal, and can be paused or
 * archived in Google Ads at any time — low risk, easily reversible, and a
 * reasonable thing to let run unattended once a human has already approved it.
 *
 * Suggested schedule: hourly, or right after daily_audit.php.
 */

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London');
$timestamp = date(DATE_ATOM);
echo "[$timestamp] Approved conversion-action executor started.\n";

try {
    $result = (new ChangeExecutor())->runPendingByType(['create_conversion_action']);

    $summary = [
        'generated_at' => date(DATE_ATOM),
        'executed' => count($result['executed']),
        'executed_ids' => $result['executed'],
        'failed' => count($result['failed']),
        'failed_ids' => $result['failed'],
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

    if ($result['failed']) {
        // Non-zero exit so a cron-monitoring/alerting setup notices, without
        // treating a partial success as a hard job failure.
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] Conversion-action executor failed: ' . $e->getMessage() . PHP_EOL);

    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
        );
        $stmt->execute([
            'context' => 'execute_conversion_actions',
            'message' => $e->getMessage(),
            'payload' => json_encode(['trace' => $e->getTraceAsString()], JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable) {
        // Best-effort logging only.
    }

    exit(1);
}
