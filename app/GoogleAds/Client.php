<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use Google\Ads\GoogleAds\Lib\V25\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V25\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V25\GoogleAdsException;
use Google\Auth\OAuth2;
use RuntimeException;

final class Client
{
    /**
     * Reads a config value from $_ENV, then $_SERVER, then getenv(), in that
     * order. Some hosts (Hostinger shared hosting included) disable PHP's
     * putenv() for security, which is how vlucas/phpdotenv normally makes a
     * loaded .env value visible to getenv() — on those hosts getenv() stays
     * empty even though $_ENV is populated correctly. Checking $_ENV first
     * makes this work regardless of whether putenv() is available.
     */
    private static function env(string $key): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return trim((string) $_ENV[$key]);
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return trim((string) $_SERVER[$key]);
        }
        $value = getenv($key);
        return $value !== false ? trim($value) : '';
    }

    public static function make(): GoogleAdsClient
    {
        $developerToken = self::env('GOOGLE_ADS_DEVELOPER_TOKEN');
        $clientId = self::env('GOOGLE_ADS_CLIENT_ID');
        $clientSecret = self::env('GOOGLE_ADS_CLIENT_SECRET');
        $refreshToken = self::env('GOOGLE_ADS_REFRESH_TOKEN');

        if ($developerToken === '' || $clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('Google Ads credentials are incomplete. Configure GOOGLE_ADS_DEVELOPER_TOKEN, GOOGLE_ADS_CLIENT_ID, GOOGLE_ADS_CLIENT_SECRET and GOOGLE_ADS_REFRESH_TOKEN.');
        }

        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->withClientId($clientId)
            ->withClientSecret($clientSecret)
            ->withRefreshToken($refreshToken)
            ->build();

        $builder = (new GoogleAdsClientBuilder())
            ->withDeveloperToken($developerToken)
            ->withOAuth2Credential($oAuth2Credential);

        $loginCustomerId = preg_replace('/\D+/', '', self::env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'));
        if (is_string($loginCustomerId) && $loginCustomerId !== '') {
            $builder->withLoginCustomerId((int) $loginCustomerId);
        }

        return $builder->build();
    }

    public static function customerId(): int
    {
        $customerId = preg_replace('/\D+/', '', self::env('GOOGLE_ADS_CUSTOMER_ID'));
        if (!is_string($customerId) || $customerId === '') {
            throw new RuntimeException('GOOGLE_ADS_CUSTOMER_ID is required.');
        }

        return (int) $customerId;
    }
}
