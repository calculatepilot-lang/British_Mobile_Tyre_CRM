<?php

declare(strict_types=1);

namespace BMT\Finance;

use BMT\Database;

/**
 * CSV import for expenses (e.g. a bank/card statement export). Every row
 * either becomes a real expense (via FinanceService::createExpense, so it
 * gets the same GBP->PKR rate-locking as manually entered rows) or is
 * rejected with a reason — nothing is silently dropped. Every import run
 * is logged permanently to finance_imports, and every expense it creates
 * is stamped with that import's UUID (expenses.import_batch_id) so an
 * entire import can be traced or reasoned about later.
 *
 * Accepted headers (case-insensitive, order-independent): date/expense_date,
 * category, payee/supplier, amount, description/note/memo. Only `date` and
 * `amount` are required — everything else is optional.
 */
final class ImportService
{
    private const MAX_ROWS = 5000;

    public function __construct(private FinanceService $finance = new FinanceService()) {}

    /**
     * @param string $filePath Path to the uploaded CSV (e.g. $_FILES[...]['tmp_name'])
     * @return array{id: string, status: string, rows_total: int, rows_imported: int, rows_rejected: int, errors: string[]}
     */
    public function importCsv(string $filePath, string $originalFilename, ?string $createdBy = null): array
    {
        $batchId = $this->uuid();
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $this->logImport($batchId, $originalFilename, 'failed', 0, 0, 0, ['Could not open uploaded file.']);
            throw new \RuntimeException('Could not open the uploaded file.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->logImport($batchId, $originalFilename, 'failed', 0, 0, 0, ['File is empty.']);
            throw new \RuntimeException('The uploaded file is empty.');
        }

        $columnIndex = $this->mapHeader($header);
        if (!isset($columnIndex['date']) || !isset($columnIndex['amount'])) {
            fclose($handle);
            $this->logImport($batchId, $originalFilename, 'failed', 0, 0, 0, [
                'Could not find required columns. Expected a date column (date/expense_date) and an amount column (amount).',
            ]);
            throw new \RuntimeException('CSV must have a date column and an amount column. Found headers: ' . implode(', ', $header));
        }

        $total = 0;
        $imported = 0;
        $errors = [];

        Database::connection()->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) continue;
                $total++;
                if ($total > self::MAX_ROWS) {
                    $errors[] = "Stopped after " . self::MAX_ROWS . " rows — split larger files.";
                    break;
                }

                try {
                    $this->importRow($row, $columnIndex, $batchId, $createdBy);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = 'Row ' . ($total + 1) . ': ' . $e->getMessage();
                }
            }
            Database::connection()->commit();
        } catch (\Throwable $e) {
            Database::connection()->rollBack();
            fclose($handle);
            $this->logImport($batchId, $originalFilename, 'failed', $total, 0, $total, [$e->getMessage()]);
            throw $e;
        }
        fclose($handle);

        $rejected = $total - $imported;
        $status = $rejected === 0 ? 'completed' : ($imported === 0 ? 'failed' : 'needs_review');
        $this->logImport($batchId, $originalFilename, $status, $total, $imported, $rejected, $errors);

        return [
            'id' => $batchId,
            'status' => $status,
            'rows_total' => $total,
            'rows_imported' => $imported,
            'rows_rejected' => $rejected,
            'errors' => $errors,
        ];
    }

    private function importRow(array $row, array $columnIndex, string $batchId, ?string $createdBy): void
    {
        $get = static fn(string $key): ?string => isset($columnIndex[$key], $row[$columnIndex[$key]]) ? trim((string) $row[$columnIndex[$key]]) : null;

        $rawDate = $get('date');
        $rawAmount = $get('amount');

        if ($rawDate === null || $rawDate === '') {
            throw new \InvalidArgumentException('Missing date.');
        }
        if ($rawAmount === null || $rawAmount === '') {
            throw new \InvalidArgumentException('Missing amount.');
        }

        $date = $this->parseDate($rawDate);
        if ($date === null) {
            throw new \InvalidArgumentException("Unrecognised date '{$rawDate}'. Use YYYY-MM-DD or DD/MM/YYYY.");
        }

        // Strip currency symbols/commas so "£1,234.50" and "1234.50" both parse.
        $amount = (float) preg_replace('/[^0-9.\-]/', '', $rawAmount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Invalid amount '{$rawAmount}' — must be a positive number.");
        }

        $this->finance->createExpense([
            'expense_date' => $date,
            'category' => $get('category') ?: 'Uncategorised',
            'category_id' => '',
            'payee' => $get('payee'),
            'description' => $get('description'),
            'amount_gbp' => $amount,
        ], $createdBy, $batchId);
    }

    private function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd.m.Y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                return $dt->format('Y-m-d');
            }
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /**
     * Case-insensitive, alias-aware header mapping. Returns ['date' => 2, 'amount' => 4, ...]
     * for whichever recognised columns are present, regardless of their order in the file.
     */
    private function mapHeader(array $header): array
    {
        $aliases = [
            'date' => ['date', 'expense_date', 'transaction date'],
            'category' => ['category', 'type'],
            'payee' => ['payee', 'supplier', 'merchant', 'vendor'],
            'amount' => ['amount', 'value', 'total'],
            'description' => ['description', 'note', 'notes', 'memo', 'details'],
        ];
        $normalised = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ($names as $name) {
                $idx = array_search($name, $normalised, true);
                if ($idx !== false) { $map[$field] = $idx; break; }
            }
        }
        return $map;
    }

    private function logImport(string $id, string $filename, string $status, int $total, int $imported, int $rejected, array $errors): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO finance_imports (id, source_type, original_filename, status, rows_total, rows_imported, rows_rejected, error_summary, completed_at)
             VALUES (:id, \'csv\', :filename, :status, :total, :imported, :rejected, :errors, NOW())'
        );
        $stmt->execute([
            'id' => $id,
            'filename' => $filename,
            'status' => $status,
            'total' => $total,
            'imported' => $imported,
            'rejected' => $rejected,
            'errors' => $errors ? implode("\n", array_slice($errors, 0, 100)) : null,
        ]);
    }

    public function listImports(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return Database::connection()->query(
            'SELECT * FROM finance_imports ORDER BY created_at DESC LIMIT ' . $limit
        )->fetchAll();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
