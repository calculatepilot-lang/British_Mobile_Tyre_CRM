<?php

declare(strict_types=1);

namespace App\Conversions;

use App\Database;

final class WebsiteEventRecorder
{
    private const ALLOWED = ['click_to_call', 'whatsapp_contact', 'lead_form'];

    public function __construct(private Database $database) {}

    public function record(string $eventType, ?int $leadId, array $attribution = []): void
    {
        if (!in_array($eventType, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Unsupported website conversion event.');
        }

        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO lead_events (lead_id, event_type, event_data, created_at)
             VALUES (:lead_id, :event_type, :event_data, NOW())'
        );
        $stmt->execute([
            'lead_id' => $leadId,
            'event_type' => $eventType,
            'event_data' => json_encode($attribution, JSON_THROW_ON_ERROR),
        ]);
    }
}
