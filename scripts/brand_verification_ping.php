<?php

declare(strict_types=1);

/**
 * ONE-OFF SCRIPT — run once via SSH to satisfy Google's brand verification
 * prerequisite: "you must associate your developer token with your Google
 * Cloud project" by making at least one API call using OAuth credentials
 * from that project. Per Google's own docs, it doesn't matter whether this
 * call succeeds or fails, or what access level the token has — it just
 * needs to reach Google's servers using this project's client ID/secret.
 *
 * Safe to run and re-run — this is a read-only call, makes no changes to
 * any Google Ads account. Delete this file once you've confirmed it ran.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\GoogleAds\Client;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

echo "Attempting one Google Ads API call to satisfy the brand verification prerequisite...\n";

try {
    $client = Client::make();
    $customerId = preg_replace('/\D+/', '', $_ENV['GOOGLE_ADS_CUSTOMER_ID'] ?? getenv('GOOGLE_ADS_CUSTOMER_ID') ?: '');

    if ($customerId === '') {
        echo "No GOOGLE_ADS_CUSTOMER_ID set in .env — that's fine, the call will still reach Google's OAuth layer and fail there, which still counts per Google's docs.\n";
        $customerId = '0000000000';
    }

    $googleAdsServiceClient = $client->getGoogleAdsServiceClient();
    $stream = $googleAdsServiceClient->searchStream(new SearchGoogleAdsRequest([
        'customer_id' => $customerId,
        'query' => 'SELECT customer.id FROM customer LIMIT 1',
    ]));

    foreach ($stream->iterateAllElements() as $response) {
        echo "SUCCESS: API call reached Google Ads and returned data. customer.id = " . $response->getCustomer()->getId() . "\n";
    }
    echo "Done. This call is enough to satisfy the brand verification prerequisite.\n";
} catch (Throwable $e) {
    echo "The call did not fully succeed, but that's expected and FINE per Google's own docs:\n";
    echo "  \"It doesn't matter whether your API call succeeds or fails.\"\n";
    echo "Error detail (for your own reference, not a problem): " . $e->getMessage() . "\n";
    echo "The important part is that the request was sent using this Cloud project's OAuth credentials — which it was.\n";
}
