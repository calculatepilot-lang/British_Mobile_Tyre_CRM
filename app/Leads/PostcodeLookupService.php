<?php

declare(strict_types=1);

namespace BMT\Leads;

use Throwable;

/**
 * Resolves a UK postcode to a human-readable city/suburb using postcodes.io
 * — a free, no-API-key public lookup covering the whole of the UK postcode
 * register. Used to auto-fill the city column when a lead arrives with a
 * postcode but no city (the common case for web-form submissions, which
 * only ever ask for postcode).
 *
 * Fails silently on any network/API problem — a lookup failure should never
 * block lead creation. Short timeout (3s) so a slow/unreachable API can't
 * noticeably delay a form submission.
 */
final class PostcodeLookupService
{
    private const ENDPOINT = 'https://api.postcodes.io/postcodes/';
    private const TIMEOUT_SECONDS = 3;

    /**
     * Returns a city/town name for the given UK postcode, or null if the
     * postcode is invalid, unrecognised, or the lookup fails for any reason.
     * Prefers admin_district (e.g. "Barking and Dagenham") when it reads as
     * a real place name; falls back to post_town, then parish.
     */
    public function lookup(string $postcode): ?string
    {
        $postcode = trim($postcode);
        if ($postcode === '') {
            return null;
        }

        try {
            $ch = curl_init(self::ENDPOINT . rawurlencode($postcode));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $httpCode !== 200) {
                return null;
            }

            $data = json_decode($raw, true);
            $result = $data['result'] ?? null;
            if (!is_array($result)) {
                return null;
            }

            $postTown = trim((string) ($result['post_town'] ?? ''));
            $district = trim((string) ($result['admin_district'] ?? ''));
            $parish = trim((string) ($result['parish'] ?? ''));

            // post_town reads most naturally for a UK address (e.g. "Ashford"
            // rather than "Ashford District Council") — prefer it, then fall
            // back to district/parish for postcodes where post_town is blank.
            foreach ([$postTown, $district, $parish] as $candidate) {
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
