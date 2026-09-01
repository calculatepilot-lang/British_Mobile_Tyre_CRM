<?php

declare(strict_types=1);

namespace BMT\Leads;

use BMT\Database;
use PDO;
use RuntimeException;

final class LeadRepository
{
    private const STATUSES = ['new','contacted','qualified','quoted','booked','completed','lost','spam','duplicate','existing_customer'];

    public function list(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT l.*, a.gclid, a.campaign_name, a.ad_group_name, a.keyword_text FROM leads l LEFT JOIN lead_attribution a ON a.lead_id = l.id ORDER BY l.created_at DESC LIMIT ' . $limit;
        return Database::connection()->query($sql)->fetchAll();
    }

    public function find(string $publicId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT l.*, a.gclid, a.gbraid, a.wbraid, a.campaign_name, a.ad_group_name, a.keyword_text, a.match_type, a.landing_page, a.utm_source, a.utm_medium, a.utm_campaign FROM leads l LEFT JOIN lead_attribution a ON a.lead_id = l.id WHERE l.public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);
        $lead = $stmt->fetch();
        return $lead ?: null;
    }

    public function updateOutcome(string $publicId, array $data, int $userId): void
    {
        $status = $data['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Invalid lead status.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $before = $this->find($publicId);
            if ($before === null) {
                throw new RuntimeException('Lead not found.');
            }

            $stmt = $pdo->prepare('UPDATE leads SET status = :status, customer_name = :customer_name, customer_phone = :customer_phone, customer_email = :customer_email, service_requested = :service_requested, tyre_size = :tyre_size, vehicle_registration = :vehicle_registration, locking_nut = :locking_nut, vehicle_type = :vehicle_type, city = :city, postcode = :postcode, quoted_amount = :quoted_amount, final_revenue = :final_revenue, outcome_reason = :outcome_reason, remarks = :remarks, quality_score = :quality_score WHERE public_id = :public_id');
            $lockingNut = strtolower(trim((string) ($data['locking_nut'] ?? '')));
            $vehicleType = \BMT\Leads\LeadService::normaliseVehicleType($data['vehicle_type'] ?? null);
            $stmt->execute([
                'status' => $status,
                'customer_name' => $data['customer_name'] ?: null,
                'customer_phone' => $data['customer_phone'] ?: null,
                'customer_email' => $data['customer_email'] ?: null,
                'service_requested' => $data['service_requested'] ?: null,
                'tyre_size' => $data['tyre_size'] ?: null,
                'vehicle_registration' => $data['vehicle_registration'] ?: null,
                'locking_nut' => in_array($lockingNut, ['yes', 'no'], true) ? $lockingNut : null,
                'vehicle_type' => $vehicleType,
                'city' => $data['city'] ?: null,
                'postcode' => $data['postcode'] ?: null,
                'quoted_amount' => $this->decimal($data['quoted_amount'] ?? null),
                'final_revenue' => $this->decimal($data['final_revenue'] ?? null),
                'outcome_reason' => $data['outcome_reason'] ?: null,
                'remarks' => $data['remarks'] ?: null,
                'quality_score' => $this->score($data['quality_score'] ?? null),
                'public_id' => $publicId,
            ]);

            $event = $pdo->prepare('INSERT INTO lead_events (lead_id, event_type, event_data) VALUES (:lead_id, :event_type, :event_data)');
            $event->execute([
                'lead_id' => $before['id'],
                'event_type' => 'lead_updated',
                'event_data' => json_encode(['by_user_id' => $userId, 'before_status' => $before['status'], 'after_status' => $status], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Deletes a lead outright. lead_attribution and lead_events both carry
     * ON DELETE CASCADE foreign keys to leads(id), so they're cleaned up
     * automatically — no manual cleanup needed. income.lead_id is a plain
     * nullable column with no FK, so any income row that once referenced
     * this lead simply keeps its historical amount with a dangling id.
     */
    public function delete(string $publicId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM leads WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
    }

    public function dashboard(): array
    {
        $pdo = Database::connection();
        $today = $pdo->query("SELECT COUNT(*) AS total, SUM(status = 'qualified') AS qualified, SUM(status = 'booked') AS booked, SUM(status = 'completed') AS completed, COALESCE(SUM(CASE WHEN status = 'completed' THEN final_revenue ELSE 0 END), 0) AS revenue FROM leads WHERE DATE(created_at) = CURDATE()")->fetch();
        $pipeline = $pdo->query("SELECT status, COUNT(*) AS total FROM leads GROUP BY status ORDER BY total DESC")->fetchAll();
        $recent = $this->list(10);
        return ['today' => $today ?: [], 'pipeline' => $pipeline, 'recent' => $recent];
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value) || (float) $value < 0) throw new RuntimeException('Invalid monetary value.');
        return number_format((float) $value, 2, '.', '');
    }

    private function score(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!filter_var($value, FILTER_VALIDATE_INT) && $value !== '0' && $value !== 0) throw new RuntimeException('Invalid quality score.');
        $score = (int) $value;
        if ($score < 0 || $score > 100) throw new RuntimeException('Quality score must be between 0 and 100.');
        return $score;
    }
}
