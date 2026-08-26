<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\Notifications\WhatsAppClient;
use App\Optimisation\OptimiserService;
use App\Reports\DailyCampaignReport;

$config = require dirname(__DIR__) . '/config/notifications.php';
if (empty($config['daily_report']['enabled']) || getenv('WHATSAPP_ENABLED') !== 'true') {
    exit("Daily WhatsApp reporting disabled.\n");
}

$date = (new DateTimeImmutable('yesterday', new DateTimeZone($config['daily_report']['schedule_timezone'])))->format('Y-m-d');
$db = Database::fromEnvironment();
$report = (new DailyCampaignReport($db))->build($date);
$recommendations = (new OptimiserService($db))->recommendations($date);

$client = new WhatsAppClient(
    (string) getenv('WHATSAPP_PHONE_NUMBER_ID'),
    (string) getenv('WHATSAPP_ACCESS_TOKEN')
);

$values = [
    $report['date'],
    '£' . number_format($report['cost'], 2),
    (string) $report['impressions'],
    (string) $report['clicks'],
    (string) $report['conversions'],
    (string) $report['leads'],
    (string) $report['qualified'],
    (string) $report['booked'],
    (string) $report['completed'],
    '£' . number_format($report['revenue'], 2),
    (string) count($recommendations),
];

$response = $client->sendTemplate(
    (string) getenv('WHATSAPP_RECIPIENT'),
    (string) getenv('WHATSAPP_TEMPLATE_NAME'),
    (string) (getenv('WHATSAPP_TEMPLATE_LANGUAGE') ?: 'en_GB'),
    $values
);

fwrite(STDOUT, 'WhatsApp daily report sent: ' . json_encode($response) . PHP_EOL);
