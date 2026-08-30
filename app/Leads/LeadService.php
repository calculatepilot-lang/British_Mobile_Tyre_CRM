<?php

declare(strict_types=1);

namespace BMT\Leads;

use BMT\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class LeadService
{
    private const ALLOWED_TYPES = ['phone', 'whatsapp', 'form', 'purchase', 'other'];

    /** Minutes within which a matching phone or email is treated as a duplicate submission. */
    private const DUPLICATE_WINDOW_MINUTES = 30;

    /**
     * Turns a page URL into a human-readable label when the caller didn't
     * already supply one — e.g. '/mobile-tyre-repair/london/' becomes
     * 'Mobile Tyre Repair London', and the homepage becomes 'Home'. This is
     * a fallback only: if the frontend sends source_page_label directly
     * (e.g. from document.title), that's always preferred since it can't
     * misread a slug the way a mechanical URL parse can.
     */
    public static function deriveSourcePageLabel(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: '/'), '/');
        if ($path === '') {
            return 'Home';
        }

        $segments = array_filter(explode('/', $path), static fn($s) => $s !== '');
        $words = [];
        foreach ($segments as $segment) {
            $words[] = ucwords(str_replace(['-', '_'], ' ', $segment));
        }

        return implode(' ', $words);
    }

    public function create(array $lead, array $attribution = []): string
    {
        $type = $lead['lead_type'] ?? '';
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->logError('lead_intake', 'Invalid lead type.', $lead);
            throw new RuntimeException('Invalid lead type.');
        }

        $pdo = Database::connection();

        $duplicateOf = $this->findRecentDuplicate($pdo, $lead);

        $publicId = $this->newPublicId();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('INSERT INTO leads (public_id, status, lead_type, source, customer_name, customer_phone, customer_email, service_requested, tyre_size, vehicle_registration, city, postcode, language, source_page_url, source_page_label) VALUES (:public_id, :status, :lead_type, :source, :customer_name, :customer_phone, :customer_email, :service_requested, :tyre_size, :vehicle_registration, :city, :postcode, :language, :source_page_url, :source_page_label)');
            $sourcePageUrl = $lead['source_page_url'] ?? null;
            $stmt->execute([
                'public_id' => $publicId,
                'status' => $duplicateOf !== null ? 'duplicate' : 'new',
                'lead_type' => $type,
                'source' => $lead['source'] ?? 'direct',
                'customer_name' => $lead['customer_name'] ?? null,
                'customer_phone' => $lead['customer_phone'] ?? null,
                'customer_email' => $lead['customer_email'] ?? null,
                'service_requested' => $lead['service_requested'] ?? null,
                'tyre_size' => $lead['tyre_size'] ?? null,
                'vehicle_registration' => $lead['vehicle_registration'] ?? null,
                'city' => $lead['city'] ?? null,
                'postcode' => $lead['postcode'] ?? null,
                'language' => $this->normaliseLanguage($lead['language'] ?? null),
                'source_page_url' => $sourcePageUrl,
                'source_page_label' => $lead['source_page_label'] ?? self::deriveSourcePageLabel($sourcePageUrl),
            ]);

            $leadId = (int) $pdo->lastInsertId();
            $attr = $pdo->prepare('INSERT INTO lead_attribution (lead_id, gclid, gbraid, wbraid, campaign_id, campaign_name, ad_group_id, ad_group_name, keyword_text, match_type, landing_page, utm_source, utm_medium, utm_campaign) VALUES (:lead_id, :gclid, :gbraid, :wbraid, :campaign_id, :campaign_name, :ad_group_id, :ad_group_name, :keyword_text, :match_type, :landing_page, :utm_source, :utm_medium, :utm_campaign)');
            $attr->execute([
                'lead_id' => $leadId,
                'gclid' => $attribution['gclid'] ?? null,
                'gbraid' => $attribution['gbraid'] ?? null,
                'wbraid' => $attribution['wbraid'] ?? null,
                'campaign_id' => $attribution['campaign_id'] ?? null,
                'campaign_name' => $attribution['campaign_name'] ?? null,
                'ad_group_id' => $attribution['ad_group_id'] ?? null,
                'ad_group_name' => $attribution['ad_group_name'] ?? null,
                'keyword_text' => $attribution['keyword_text'] ?? null,
                'match_type' => $attribution['match_type'] ?? null,
                'landing_page' => $attribution['landing_page'] ?? null,
                'utm_source' => $attribution['utm_source'] ?? null,
                'utm_medium' => $attribution['utm_medium'] ?? null,
                'utm_campaign' => $attribution['utm_campaign'] ?? null,
            ]);

            if ($duplicateOf !== null) {
                $event = $pdo->prepare('INSERT INTO lead_events (lead_id, event_type, event_data) VALUES (:lead_id, :event_type, :event_data)');
                $event->execute([
                    'lead_id' => $leadId,
                    'event_type' => 'duplicate_detected',
                    'event_data' => json_encode(['matched_lead_public_id' => $duplicateOf], JSON_THROW_ON_ERROR),
                ]);
            }

            $pdo->commit();
            return $publicId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->logError('lead_intake', $e->getMessage(), $lead);
            throw $e;
        }
    }

    /**
     * Looks for a lead with the same phone or email created within the
     * duplicate window. Returns that lead's public_id, or null if none found.
     * Matching is skipped for a field that's blank on the incoming lead.
     */
    private function findRecentDuplicate(PDO $pdo, array $lead): ?string
    {
        $phone = trim((string) ($lead['customer_phone'] ?? ''));
        $email = trim((string) ($lead['customer_email'] ?? ''));

        if ($phone === '' && $email === '') {
            return null;
        }

        $conditions = [];
        $params = ['minutes' => self::DUPLICATE_WINDOW_MINUTES];

        if ($phone !== '') {
            $conditions[] = 'customer_phone = :phone';
            $params['phone'] = $phone;
        }
        if ($email !== '') {
            $conditions[] = 'customer_email = :email';
            $params['email'] = $email;
        }

        $sql = 'SELECT public_id FROM leads
                WHERE (' . implode(' OR ', $conditions) . ')
                AND created_at >= (NOW() - INTERVAL :minutes MINUTE)
                ORDER BY created_at DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();

        return $result !== false ? (string) $result : null;
    }

    private function normaliseLanguage(mixed $language): string
    {
        $lang = strtolower(trim((string) ($language ?? '')));
        $lang = str_replace('_', '-', $lang);
        if ($lang === '' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
            return 'en';
        }
        return $lang;
    }

    private function logError(string $context, string $message, array $payload = []): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO error_logs (context, message, payload) VALUES (:context, :message, :payload)'
            );
            $stmt->execute([
                'context' => $context,
                'message' => $message,
                'payload' => json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (\Throwable) {
            // Logging must never mask or replace the original exception.
        }
    }

    private function newPublicId(): string
    {
        return 'BMT-' . (new DateTimeImmutable('now'))->format('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
