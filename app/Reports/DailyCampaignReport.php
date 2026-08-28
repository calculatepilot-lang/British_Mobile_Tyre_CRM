<?php

declare(strict_types=1);

namespace BMT\Reports;

use BMT\Database;

final class DailyCampaignReport
{
    public function __construct(private Database $database) {}

    public function build(string $date): array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(clicks),0) clicks,
                    COALESCE(SUM(impressions),0) impressions,
                    COALESCE(SUM(cost_micros),0) cost_micros,
                    COALESCE(SUM(conversions),0) conversions
             FROM daily_campaign_metrics
             WHERE metric_date = :date"
        );
        $stmt->execute(['date' => $date]);
        $ads = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $leadStmt = $pdo->prepare(
            "SELECT COUNT(*) leads,
                    SUM(status IN ('qualified','quoted','booked','completed')) qualified,
                    SUM(status = 'booked') booked,
                    SUM(status = 'completed') completed,
                    COALESCE(SUM(final_revenue),0) revenue
             FROM leads
             WHERE DATE(created_at) = :date"
        );
        $leadStmt->execute(['date' => $date]);
        $crm = $leadStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $cost = ((int)($ads['cost_micros'] ?? 0)) / 1000000;
        return [
            'date' => $date,
            'impressions' => (int)($ads['impressions'] ?? 0),
            'clicks' => (int)($ads['clicks'] ?? 0),
            'cost' => round($cost, 2),
            'conversions' => (float)($ads['conversions'] ?? 0),
            'leads' => (int)($crm['leads'] ?? 0),
            'qualified' => (int)($crm['qualified'] ?? 0),
            'booked' => (int)($crm['booked'] ?? 0),
            'completed' => (int)($crm['completed'] ?? 0),
            'revenue' => round((float)($crm['revenue'] ?? 0), 2),
        ];
    }

    public function formatWhatsApp(array $r): string
    {
        return "BMT Google Ads Daily Report — {$r['date']}\n\n"
            . "Spend: PKR {$r['cost']}\n"
            . "Impressions: {$r['impressions']}\n"
            . "Clicks: {$r['clicks']}\n"
            . "Google Ads conversions: {$r['conversions']}\n\n"
            . "CRM leads: {$r['leads']}\n"
            . "Qualified: {$r['qualified']}\n"
            . "Booked: {$r['booked']}\n"
            . "Completed: {$r['completed']}\n"
            . "Recorded revenue: £{$r['revenue']}";
    }
}
