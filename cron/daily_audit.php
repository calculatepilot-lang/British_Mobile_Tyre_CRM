<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/London');

if ((getenv('AUTOMATION_MODE') ?: 'audit_only') !== 'audit_only') {
    fwrite(STDERR, "Daily audit refuses to run mutations until a dedicated execution workflow is enabled.\n");
}

$timestamp = date(DATE_ATOM);
echo "[$timestamp] BMT daily audit foundation job started in audit-only mode.\n";
// Google Ads data collection and decision generation will be added before any mutation capability.
