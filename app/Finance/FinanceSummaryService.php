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

    public function summary(string $from, string $to): array
    {
        $pdo = $this->database->pdo();
        $earned = $pdo->prepare("SELECT COALESCE(SUM(earned_gbp),0) FROM leads WHERE status = 'converted' AND converted_at >= :from AND converted_at < DATE_ADD(:to, INTERVAL 1 DAY)");
        $earned->execute(['from'=>$from, 'to'=>$to]);
        $income = (float)$earned->fetchColumn();

        $expense = $pdo->prepare("SELECT COALESCE(SUM(amount_gbp),0) FROM expenses WHERE expense_date BETWEEN :from AND :to");
        $expense->execute(['from'=>$from, 'to'=>$to]);
        $outgoings = (float)$expense->fetchColumn();

        return ['from'=>$from,'to'=>$to,'earned_gbp'=>round($income,2),'expenses_gbp'=>round($outgoings,2),'net_gbp'=>round($income-$outgoings,2)];
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
