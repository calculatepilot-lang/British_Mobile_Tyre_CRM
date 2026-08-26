<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenvPath = dirname(__DIR__);
if (is_file($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($dotenvPath)->safeLoad();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/London');

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>British Mobile Tyres CRM</title>
</head>
<body>
    <main>
        <h1>British Mobile Tyres CRM</h1>
        <p>Foundation deployment is active.</p>
        <p>Automation mode: <strong><?= htmlspecialchars(getenv('AUTOMATION_MODE') ?: 'audit_only', ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p>Google Ads mutations remain disabled until the audit, authentication, conversion setup and approval controls are complete.</p>
    </main>
</body>
</html>
