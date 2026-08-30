<?php
declare(strict_types=1);

namespace BMT\Finance;

use BMT\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class FinanceSummaryService
{
    public function __construct(private Database $database) {}

    /**
     * GBP totals as before, PLUS pkr_expenses (summed from each expense's
     * own locked amount_pkr — never recomputed at today's rate, since the
     * whole point of locking is that a June transaction stays at June's
     * rate forever, even if you run this report in December).
     */
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

        $expense = $pdo->prepare("SELECT COALESCE(SUM(amount_gbp),0), COALESCE(SUM(amount_pkr),0) FROM expenses WHERE expense_date BETWEEN :from AND :to");
        $expense->execute(['from'=>$from, 'to'=>$to]);
        [$outgoingsGbp, $outgoingsPkr] = $expense->fetch(PDO::FETCH_NUM);

        $byCategory = $pdo->prepare(
            "SELECT COALESCE(c.name, 'Uncategorised') AS category, SUM(e.amount_gbp) AS gbp, SUM(e.amount_pkr) AS pkr, COUNT(*) AS count
             FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
             WHERE e.expense_date BETWEEN :from AND :to
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
