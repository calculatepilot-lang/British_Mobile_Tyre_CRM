<?php

declare(strict_types=1);

namespace BMT\Finance;

use BMT\Database;
use RuntimeException;

/**
 * Fetches the current GBP->PKR exchange rate on demand, caching it for a
 * short window (default 6 hours) so repeated expense entries in the same
 * session don't all trigger separate API calls. Every live fetch is logged
 * to exchange_rate_log permanently for audit purposes — the cache only
 * controls how often a NEW row is written, it never deletes history.
 *
 * Uses open.er-api.com — free, no API key required, updates daily. Swap
 * the source in fetchLiveRate() if you later want a provider with more
 * frequent updates or a paid SLA.
 */
final class ExchangeRateService
{
    private const BASE = 'GBP';
    private const QUOTE = 'PKR';
    private const CACHE_WINDOW_HOURS = 6;

    /**
     * Returns the rate to use right now — either the most recent cached
     * fetch (if still within the cache window) or a fresh live fetch,
     * which is logged before being returned.
     */
    public function currentRate(): float
    {
        $cached = $this->cachedRate();
        if ($cached !== null) {
            return $cached;
        }

        $rate = $this->fetchLiveRate();
        $this->log($rate);
        return $rate;
    }

    private function cachedRate(): ?float
    {
        $stmt = Database::connection()->prepare(
            'SELECT rate FROM exchange_rate_log
             WHERE base_currency = :base AND quote_currency = :quote
             AND fetched_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY fetched_at DESC LIMIT 1'
        );
        $stmt->execute(['base' => self::BASE, 'quote' => self::QUOTE, 'hours' => self::CACHE_WINDOW_HOURS]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (float) $value : null;
    }

    private function fetchLiveRate(): float
    {
        $url = 'https://open.er-api.com/v6/latest/' . self::BASE;
        $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            // Fall back to the most recent rate we ever recorded, even if
            // stale, rather than blocking expense entry entirely.
            $fallback = $this->mostRecentEverLogged();
            if ($fallback !== null) return $fallback;
            throw new RuntimeException('Could not reach the exchange rate provider and no previous rate is on record. Enter the rate manually or try again shortly.');
        }

        $data = json_decode($raw, true);
        $rate = $data['rates'][self::QUOTE] ?? null;

        if (!is_numeric($rate)) {
            $fallback = $this->mostRecentEverLogged();
            if ($fallback !== null) return $fallback;
            throw new RuntimeException('Exchange rate provider did not return a ' . self::QUOTE . ' rate.');
        }

        return (float) $rate;
    }

    private function mostRecentEverLogged(): ?float
    {
        $stmt = Database::connection()->prepare(
            'SELECT rate FROM exchange_rate_log WHERE base_currency = :base AND quote_currency = :quote ORDER BY fetched_at DESC LIMIT 1'
        );
        $stmt->execute(['base' => self::BASE, 'quote' => self::QUOTE]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (float) $value : null;
    }

    private function log(float $rate): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO exchange_rate_log (base_currency, quote_currency, rate, source) VALUES (:base, :quote, :rate, :source)'
        );
        $stmt->execute(['base' => self::BASE, 'quote' => self::QUOTE, 'rate' => $rate, 'source' => 'open.er-api.com']);
    }
}
