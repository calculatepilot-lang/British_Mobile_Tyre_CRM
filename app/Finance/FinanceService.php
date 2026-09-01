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
 * this service. `amount`/`currency` stay GBP-denominated everywhere else in
 * the app (reports, summaries); `input_currency`/`input_amount` (added in
 * finance_v3_migration.sql) separately record what the user actually typed,
 * so a GBP/PKR switcher on entry doesn't disturb any GBP-based reporting.
 * converted_amount_pkr is the PKR value locked at entry time, for reference
 * against the PKR-denominated Ads spend.
 */
final class FinanceService
{
    private const CURRENCIES = ['GBP', 'PKR'];

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
     * Resolves whatever currency + amount was submitted (GBP or PKR) into
     * the GBP amount the rest of the app stores, plus the PKR-locked figure,
     * using the live GBP->PKR rate. Returns [amountGbp, inputCurrency,
     * inputAmount, rate, convertedPkr] so callers can both insert a new row
     * and re-lock an edited one with identical logic.
     */
    private function resolveAmount(array $data): array
    {
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'GBP')));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new \InvalidArgumentException('Currency must be GBP or PKR.');
        }

        $rate = $this->rates->currentRate(); // GBP -> PKR

        if ($currency === 'PKR') {
            $inputAmount = (float) ($data['amount'] ?? 0);
            if ($inputAmount <= 0) {
                throw new \InvalidArgumentException('Expense amount must be greater than zero.');
            }
            $amountGbp = round($inputAmount / $rate, 2);
            $convertedPkr = round($inputAmount, 2);
        } else {
            // 'amount_gbp' kept for backward compatibility with ImportService,
            // which always submits GBP amounts under that key.
            $inputAmount = (float) ($data['amount_gbp'] ?? $data['amount'] ?? 0);
            if ($inputAmount <= 0) {
                throw new \InvalidArgumentException('Expense amount must be greater than zero.');
            }
            $amountGbp = $inputAmount;
            $convertedPkr = round($amountGbp * $rate, 2);
        }

        return [$amountGbp, $currency, $inputAmount, $rate, $convertedPkr];
    }

    /**
     * Creates an expense, fetching and locking the current GBP->PKR rate at
     * the moment of creation into the v1 columns exchange_rate_to_pkr /
     * converted_amount_pkr / rate_locked_at. The locked rate never changes
     * retroactively, even if the live rate moves later. Accepts either a GBP
     * amount (default) or a PKR amount with currency=PKR, converting to the
     * GBP figure the rest of the app relies on.
     */
    public function createExpense(array $data, ?string $createdBy = null, ?string $importBatchId = null): int
    {
        [$amountGbp, $inputCurrency, $inputAmount, $rate, $convertedPkr] = $this->resolveAmount($data);

        $stmt = Database::connection()->prepare(
            'INSERT INTO expenses
                (expense_date, category, category_id, description, amount, currency,
                 input_currency, input_amount, exchange_rate_to_pkr, rate_source, rate_locked_at,
                 converted_amount_pkr, supplier, import_batch_id, created_by)
             VALUES
                (:expense_date, :category, :category_id, :description, :amount, \'GBP\',
                 :input_currency, :input_amount, :rate, :rate_source, NOW(),
                 :converted_pkr, :supplier, :import_batch_id, :created_by)'
        );
        $stmt->execute([
            'expense_date' => $data['expense_date'] ?? date('Y-m-d'),
            'category' => $data['category'] ?? 'Uncategorised',
            'category_id' => ($data['category_id'] ?? '') !== '' ? (int) $data['category_id'] : null,
            'description' => $data['description'] ?? null,
            'amount' => $amountGbp,
            'input_currency' => $inputCurrency,
            'input_amount' => $inputAmount,
            'rate' => $rate,
            'rate_source' => 'open.er-api.com',
            'converted_pkr' => $convertedPkr,
            'supplier' => $data['payee'] ?? null,
            'import_batch_id' => $importBatchId,
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

    public function getExpense(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM expenses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Updates an expense. If the amount and currency are unchanged from the
     * existing row (i.e. this edit only touched category/description/date/
     * payee), the originally locked rate and converted PKR figure are left
     * untouched — a typo fix shouldn't silently move a transaction's PKR
     * value. If the amount or currency actually changed, a fresh rate is
     * fetched and re-locked, since that's a genuinely different transaction.
     */
    public function updateExpense(int $id, array $data, ?string $updatedBy = null): void
    {
        $existing = $this->getExpense($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Expense not found.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? $existing['input_currency'] ?? 'GBP')));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new \InvalidArgumentException('Currency must be GBP or PKR.');
        }
        $inputAmount = (float) ($data['amount'] ?? 0);
        if ($inputAmount <= 0) {
            throw new \InvalidArgumentException('Expense amount must be greater than zero.');
        }

        $unchanged = $currency === strtoupper((string) ($existing['input_currency'] ?? 'GBP'))
            && abs($inputAmount - (float) ($existing['input_amount'] ?? $existing['amount'])) < 0.005;

        if ($unchanged) {
            $amountGbp = (float) $existing['amount'];
            $rate = (float) $existing['exchange_rate_to_pkr'];
            $convertedPkr = (float) $existing['converted_amount_pkr'];
        } else {
            $rate = $this->rates->currentRate();
            if ($currency === 'PKR') {
                $amountGbp = round($inputAmount / $rate, 2);
                $convertedPkr = round($inputAmount, 2);
            } else {
                $amountGbp = $inputAmount;
                $convertedPkr = round($amountGbp * $rate, 2);
            }
        }

        $stmt = Database::connection()->prepare(
            'UPDATE expenses SET
                expense_date = :expense_date,
                category = :category,
                category_id = :category_id,
                description = :description,
                amount = :amount,
                input_currency = :input_currency,
                input_amount = :input_amount,
                exchange_rate_to_pkr = :rate,
                converted_amount_pkr = :converted_pkr,
                rate_locked_at = ' . ($unchanged ? 'rate_locked_at' : 'NOW()') . ',
                supplier = :supplier,
                updated_by = :updated_by
             WHERE id = :id'
        );
        $stmt->execute([
            'expense_date' => $data['expense_date'] ?? $existing['expense_date'],
            'category' => $data['category'] ?? $existing['category'],
            'category_id' => ($data['category_id'] ?? '') !== '' ? (int) $data['category_id'] : null,
            'description' => $data['description'] ?? null,
            'amount' => $amountGbp,
            'input_currency' => $currency,
            'input_amount' => $inputAmount,
            'rate' => $rate,
            'converted_pkr' => $convertedPkr,
            'supplier' => $data['payee'] ?? null,
            'updated_by' => $updatedBy,
            'id' => $id,
        ]);
    }

    public function deleteExpense(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
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

    public function getIncomeById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM income WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateIncome(int $id, array $data, ?string $updatedBy = null): void
    {
        $amountGbp = (float) ($data['amount_gbp'] ?? 0);
        if ($amountGbp <= 0) {
            throw new \InvalidArgumentException('Income amount must be greater than zero.');
        }

        $stmt = Database::connection()->prepare(
            'UPDATE income SET source = :source, description = :description, amount_gbp = :amount_gbp,
             received_at = :received_at, updated_by = :updated_by WHERE id = :id'
        );
        $stmt->execute([
            'source' => $data['source'] ?? 'manual',
            'description' => $data['description'] ?? null,
            'amount_gbp' => $amountGbp,
            'received_at' => $data['received_at'] ?? date('Y-m-d'),
            'updated_by' => $updatedBy,
            'id' => $id,
        ]);
    }

    public function deleteIncome(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM income WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
