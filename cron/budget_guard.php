<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Database;
use BMT\Optimisation\OptimiserService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London');
$timestamp = date(DATE_ATOM);
echo "[$timestamp] BMT budget guard started.\n";

if ((($_ENV['AUTOMATION_MODE'] ?? $_SERVER['AUTOMATION_MODE'] ?? getenv('AUTOMATION_MODE') ?: null) ?: 'audit_only') !== 'audit_only') {
    fwrite(STDERR, "Mutation mode is not implemented by this job. Continuing with proposal-only optimisation.\n");
}

try {
    $date = (new DateTimeImmutable('yesterday', new DateTimeZone(($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null) ?: 'Europe/London')))->format('Y-m-d');
    $queued = (new OptimiserService(new Database()))->queueDailyOptimisations($date);

    $summary = [
        'generated_at' => date(DATE_ATOM),
        'metric_date' => $date,
        'mode' => 'audit_only',
        'optimisation_proposals_queued' => count($queued),
        'optimisation_proposal_ids' => $queued,
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] Budget guard failed: ' . $e->getMessage() . PHP_EOL);

    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
        );
        $stmt->execute([
            'context' => 'budget_guard',
            'message' => $e->getMessage(),
            'payload' => json_encode(['trace' => $e->getTraceAsString()], JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable) {
        // Best-effort logging only.
    }

    exit(1);
}
