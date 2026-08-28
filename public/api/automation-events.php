<?php

declare(strict_types=1);

/**
 * Records an automation event that already happened OUTSIDE this CRM —
 * currently, Google Ads Scripts running natively inside Google Ads (see
 * /google-ads-scripts). This is a logging endpoint, not a trigger: it never
 * causes any mutation itself, it only writes a row to automation_changes
 * with status='executed' so the event shows up on /changes alongside
 * everything the PHP side proposes and applies, giving one combined
 * history regardless of which system actually performed the action.
 *
 * Authenticated the same way as /api/leads.php (X-BMT-LEAD-KEY header
 * against LEAD_API_KEY), since it's the same trust boundary: a caller that
 * knows the CRM's shared key.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use BMT\Database;

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
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('JSON object required.');
    }

    foreach (['change_type', 'resource_type', 'reason'] as $required) {
        if (empty($data[$required])) {
            throw new RuntimeException("Missing required field: {$required}");
        }
    }

    $stmt = Database::connection()->prepare(
        'INSERT INTO automation_changes (change_uuid, change_type, resource_type, resource_name, reason, after_state, risk_level, status, reversible, executed_at)
         VALUES (:change_uuid, :change_type, :resource_type, :resource_name, :reason, :after_state, :risk_level, :status, :reversible, NOW())'
    );

    $uuid = sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffffffffffff)
    );

    $stmt->execute([
        'change_uuid' => $uuid,
        'change_type' => (string) $data['change_type'],
        'resource_type' => (string) $data['resource_type'],
        'resource_name' => isset($data['resource_name']) ? (string) $data['resource_name'] : null,
        'reason' => (string) $data['reason'],
        'after_state' => isset($data['after_state']) ? json_encode($data['after_state'], JSON_THROW_ON_ERROR) : null,
        'risk_level' => (string) ($data['risk_level'] ?? 'medium'),
        'status' => 'executed',
        'reversible' => !empty($data['reversible']) ? 1 : 0,
    ]);

    http_response_code(201);
    echo json_encode(['ok' => true, 'change_uuid' => $uuid]);
} catch (Throwable $e) {
    http_response_code(422);
    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
        );
        $stmt->execute([
            'context' => 'automation_event_rejected',
            'message' => $e->getMessage(),
            'payload' => json_encode(['raw_length' => strlen($raw ?? '')], JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable) {
        // Best-effort logging only.
    }
    echo json_encode(['ok' => false, 'error' => 'Event could not be recorded']);
}
