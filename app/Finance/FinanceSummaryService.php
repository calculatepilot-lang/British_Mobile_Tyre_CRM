<?php
declare(strict_types=1);

namespace BMT\Finance;

use BMT\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Reads use the real v1 expenses schema: amount (GBP, since every expense
 * is now recorded with currency='GBP'), converted_amount_pkr (locked at
 * entry time — never recomputed at today's rate).
 */
final class FinanceSummaryService
{
    public function __construct(private Database $database) {}

    public function summary(string $from, string $to): array
    {
        $pdo = $this->database->pdo();

        $leadEarned = $pdo->prepare("SELECT COALESCE(SUM(earned_gbp),0) FROM leads WHERE status = 'converted' AND converted_at >= :from AND converted_at < DATE_ADD(:to, INTERVAL 1 DAY)");
        $leadEarned->execute(['from'=>$from, 'to'=>$to]);
        $leadIncome = (float)$leadEarned->fetchColumn();

        $manualIncome = $pdo->prepare("SELECT COALESCE(SUM(amount_gbp),0) FROM income WHERE received_at BETWEEN :from AND :to");
        $manualIncome->execute(['from'=>$from, 'to'=>$to]);
        $manual = (float)$manualIncome->fetchColumn();

        $income = $leadIncome + $manual;

        $expense = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0), COALESCE(SUM(converted_amount_pkr),0)
             FROM expenses WHERE currency = 'GBP' AND expense_date BETWEEN :from AND :to"
        );
        $expense->execute(['from'=>$from, 'to'=>$to]);
        [$outgoingsGbp, $outgoingsPkr] = $expense->fetch(PDO::FETCH_NUM);

        $byCategory = $pdo->prepare(
            "SELECT COALESCE(c.name, e.category, 'Uncategorised') AS category,
                    SUM(e.amount) AS gbp, SUM(e.converted_amount_pkr) AS pkr, COUNT(*) AS count
             FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
             WHERE e.currency = 'GBP' AND e.expense_date BETWEEN :from AND :to
             GROUP BY category ORDER BY gbp DESC"
        );
        $byCategory->execute(['from'=>$from, 'to'=>$to]);

        return [
            'from' => $from,
            'to' => $to,
            'earned_gbp' => round($income, 2),
            'earned_from_leads_gbp' => round($leadIncome, 2),
            'earned_manual_gbp' => round($manual, 2),
            'expenses_gbp' => round((float)$outgoingsGbp, 2),
            'expenses_pkr' => round((float)$outgoingsPkr, 2),
            'net_gbp' => round($income - (float)$outgoingsGbp, 2),
            'by_category' => $byCategory->fetchAll(),
        ];
    }

    public function periods(?DateTimeZone $tz = null): array
    {
        $now = new DateTimeImmutable('now', $tz ?: new DateTimeZone('Europe/London'));
        return [
            'today' => $this->summary($now->format('Y-m-d'), $now->format('Y-m-d')),
            'month' => $this->summary($now->format('Y-m-01'), $now->format('Y-m-d')),
            'year' => $this->summary($now->format('Y-01-01'), $now->format('Y-m-d')),
        ];
    }
}
