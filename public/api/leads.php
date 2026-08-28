<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use BMT\Database;
use BMT\Leads\LeadService;

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$env = static function (string $key, string $default = ''): string {
    if (isset($_ENV[$key])) {
        return (string) $_ENV[$key];
    }

    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }

    $value = getenv($key);

    return $value !== false ? (string) $value : $default;
};

$logRejection = static function (string $message, array $context = []): void {
    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
        );
        $stmt->execute([
            'context' => 'lead_intake_rejected',
            'message' => $message,
            'payload' => json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (\Throwable) {
        // Best-effort logging only; never let a logging failure mask the real response.
    }
};

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$expected = $env('LEAD_API_KEY');
$provided = $_SERVER['HTTP_X_BMT_LEAD_KEY'] ?? '';
if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
    http_response_code(401);
    $logRejection('Unauthorized: missing or invalid X-BMT-LEAD-KEY header.');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('JSON object required.');

    $lead = $data['lead'] ?? $data;
    $attribution = $data['attribution'] ?? [];
    if (!is_array($lead) || !is_array($attribution)) throw new RuntimeException('Invalid payload.');

    $publicId = (new LeadService())->create($lead, $attribution);
    http_response_code(201);
    echo json_encode(['ok' => true, 'lead_id' => $publicId], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    $logRejection('Lead intake failed: ' . $e->getMessage(), ['raw_length' => strlen($raw ?? '')]);
    echo json_encode(['ok' => false, 'error' => 'Lead could not be created']);
}
