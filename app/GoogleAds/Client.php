<?php

declare(strict_types=1);

namespace BMT\GoogleAds;

use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsException;
use Google\Auth\OAuth2;
use RuntimeException;

final class Client
{
    public static function make(): GoogleAdsClient
    {
        $developerToken = trim((string) getenv('GOOGLE_ADS_DEVELOPER_TOKEN'));
        $clientId = trim((string) getenv('GOOGLE_ADS_CLIENT_ID'));
        $clientSecret = trim((string) getenv('GOOGLE_ADS_CLIENT_SECRET'));
        $refreshToken = trim((string) getenv('GOOGLE_ADS_REFRESH_TOKEN'));

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

        $loginCustomerId = preg_replace('/\D+/', '', (string) getenv('GOOGLE_ADS_LOGIN_CUSTOMER_ID'));
        if (is_string($loginCustomerId) && $loginCustomerId !== '') {
            $builder->withLoginCustomerId((int) $loginCustomerId);
        }

        return $builder->build();
    }

    public static function customerId(): int
    {
        $customerId = preg_replace('/\D+/', '', (string) getenv('GOOGLE_ADS_CUSTOMER_ID'));
        if (!is_string($customerId) || $customerId === '') {
            throw new RuntimeException('GOOGLE_ADS_CUSTOMER_ID is required.');
        }

        return (int) $customerId;
    }
}
