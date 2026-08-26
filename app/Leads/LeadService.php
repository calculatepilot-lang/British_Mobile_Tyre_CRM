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

    public function create(array $lead, array $attribution = []): string
    {
        $type = $lead['lead_type'] ?? '';
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('Invalid lead type.');
        }

        $publicId = $this->newPublicId();
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('INSERT INTO leads (public_id, lead_type, source, customer_name, customer_phone, customer_email, service_requested, city, postcode) VALUES (:public_id, :lead_type, :source, :customer_name, :customer_phone, :customer_email, :service_requested, :city, :postcode)');
            $stmt->execute([
                'public_id' => $publicId,
                'lead_type' => $type,
                'source' => $lead['source'] ?? 'direct',
                'customer_name' => $lead['customer_name'] ?? null,
                'customer_phone' => $lead['customer_phone'] ?? null,
                'customer_email' => $lead['customer_email'] ?? null,
                'service_requested' => $lead['service_requested'] ?? null,
                'city' => $lead['city'] ?? null,
                'postcode' => $lead['postcode'] ?? null,
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

            $pdo->commit();
            return $publicId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function newPublicId(): string
    {
        return 'BMT-' . (new DateTimeImmutable('now'))->format('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
