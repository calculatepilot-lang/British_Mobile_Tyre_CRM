<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/London');
echo '[' . date(DATE_ATOM) . "] BMT budget guard foundation check: no live Google Ads mutation enabled.\n";
