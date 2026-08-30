<?php

declare(strict_types=1);

namespace BMT\Finance;

use BMT\Database;

/**
 * Write-side finance operations: categories, expenses, and income.
 *
 * Column names deliberately match the base `expenses` table created by
 * database/finance_migration.sql (v1): amount, currency, exchange_rate_to_pkr,
 * converted_amount_pkr, rate_locked_at, supplier — NOT the invented
 * amount_gbp/amount_pkr/payee names from an earlier, incompatible draft of
 * this service. Every expense is recorded with currency='GBP' since that's
 * what the business actually pays in; converted_amount_pkr is the PKR value
 * locked at entry time, for reference against the PKR-denominated Ads spend.
 */
final class FinanceService
{
    public function __construct(private ExchangeRateService $rates = new ExchangeRateService()) {}

    // ---- Categories -------------------------------------------------

    public function listCategories(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM expense_categories';
        if (!$includeArchived) $sql .= ' WHERE archived = 0';
        $sql .= ' ORDER BY is_default DESC, name ASC';
        return Database::connection()->query($sql)->fetchAll();
    }

    public function createCategory(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO expense_categories (name) VALUES (:name) ON DUPLICATE KEY UPDATE archived = 0'
        );
        $stmt->execute(['name' => $name]);
        return (int) Database::connection()->lastInsertId();
    }

    public function archiveCategory(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE expense_categories SET archived = 1 WHERE id = :id AND is_default = 0');
        $stmt->execute(['id' => $id]);
    }

    // ---- Expenses (money out) ---------------------------------------

    /**
     * Creates an expense in GBP, fetching and locking the current GBP->PKR
     * rate at the moment of creation into the v1 columns exchange_rate_to_pkr /
     * converted_amount_pkr / rate_locked_at. The locked rate never changes
     * retroactively, even if the live rate moves later.
     */
    public function createExpense(array $data, ?string $createdBy = null): int
    {
        $amountGbp = (float) ($data['amount_gbp'] ?? 0);
        if ($amountGbp <= 0) {
            throw new \InvalidArgumentException('Expense amount must be greater than zero.');
        }

        $rate = $this->rates->currentRate();
        $convertedPkr = round($amountGbp * $rate, 2);

        $stmt = Database::connection()->prepare(
            'INSERT INTO expenses
                (expense_date, category, category_id, description, amount, currency,
                 exchange_rate_to_pkr, rate_source, rate_locked_at, converted_amount_pkr,
                 supplier, created_by)
             VALUES
                (:expense_date, :category, :category_id, :description, :amount, \'GBP\',
                 :rate, :rate_source, NOW(), :converted_pkr,
                 :supplier, :created_by)'
        );
        $stmt->execute([
            'expense_date' => $data['expense_date'] ?? date('Y-m-d'),
            'category' => $data['category'] ?? 'Uncategorised',
            'category_id' => $data['category_id'] !== '' ? (int) $data['category_id'] : null,
            'description' => $data['description'] ?? null,
            'amount' => $amountGbp,
            'rate' => $rate,
            'rate_source' => 'open.er-api.com',
            'converted_pkr' => $convertedPkr,
            'supplier' => $data['payee'] ?? null,
            'created_by' => $createdBy,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function listExpenses(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Database::connection()->query(
            'SELECT e.*, c.name AS category_name FROM expenses e
             LEFT JOIN expense_categories c ON c.id = e.category_id
             ORDER BY e.expense_date DESC, e.id DESC LIMIT ' . $limit
        )->fetchAll();
    }

    // ---- Income (money in) -------------------------------------------

    public function createIncome(array $data, ?string $createdBy = null): int
    {
        $amountGbp = (float) ($data['amount_gbp'] ?? 0);
        if ($amountGbp <= 0) {
            throw new \InvalidArgumentException('Income amount must be greater than zero.');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO income (source, description, amount_gbp, received_at, created_by)
             VALUES (:source, :description, :amount_gbp, :received_at, :created_by)'
        );
        $stmt->execute([
            'source' => $data['source'] ?? 'manual',
            'description' => $data['description'] ?? null,
            'amount_gbp' => $amountGbp,
            'received_at' => $data['received_at'] ?? date('Y-m-d'),
            'created_by' => $createdBy,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function listIncome(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Database::connection()->query(
            'SELECT * FROM income ORDER BY received_at DESC, id DESC LIMIT ' . $limit
        )->fetchAll();
    }
}
