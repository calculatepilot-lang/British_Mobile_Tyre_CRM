<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use BMT\Leads\LeadService;

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$expected = getenv('LEAD_API_KEY') ?: '';
$provided = $_SERVER['HTTP_X_BMT_LEAD_KEY'] ?? '';
if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
    http_response_code(401);
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
    echo json_encode(['ok' => false, 'error' => 'Lead could not be created']);
}
