<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Database;
use BMT\Optimisation\ClaudeDailyOptimiser;

/**
 * Runs the Claude-based daily optimisation pass — a second, LLM-reasoned
 * source of budget/pause proposals alongside the fixed-threshold rules in
 * OptimiserService (still run separately by daily_audit.php or wherever
 * queueDailyOptimisations() is already called). This job only ever queues
 * proposals for human approval on /changes; see ClaudeDailyOptimiser's
 * class docblock for the full validation/safety model.
 *
 * No-ops cleanly (exit 0, explanatory message) if CLAUDE_OPTIMISATION_ENABLED
 * is not 'true' or ANTHROPIC_API_KEY isn't set — safe to add to cron ahead
 * of actually turning the feature on.
 *
 * Suggested schedule: once daily, after daily_metrics for yesterday has
 * been collected (i.e. after daily_audit.php has run).
 */

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London');
$timestamp = date(DATE_ATOM);
$yesterday = date('Y-m-d', strtotime('yesterday'));
echo "[$timestamp] Claude daily optimisation started for $yesterday.\n";

try {
    $optimiser = new ClaudeDailyOptimiser(new Database());

    if (!$optimiser->isEnabled()) {
        echo "Claude optimisation is not enabled (CLAUDE_OPTIMISATION_ENABLED / ANTHROPIC_API_KEY) — nothing to do.\n";
        exit(0);
    }

    $result = $optimiser->queueDailyOptimisations($yesterday);

    echo json_encode([
        'generated_at' => date(DATE_ATOM),
        'date_analysed' => $yesterday,
        'queued' => count($result['queued']),
        'queued_ids' => $result['queued'],
        'skipped_existing' => $result['skipped_existing'],
        'recommendations_seen' => $result['recommendations_seen'],
        'error' => $result['error'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

    if ($result['error']) {
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] Claude daily optimisation failed: ' . $e->getMessage() . PHP_EOL);

    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
        );
        $stmt->execute([
            'context' => 'claude_daily_optimisation_cron',
            'message' => $e->getMessage(),
            'payload' => json_encode(['trace' => $e->getTraceAsString()], JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable) {
        // Best-effort logging only.
    }

    exit(1);
}
