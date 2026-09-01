-- Finance v3: edit/delete support for expenses & income, plus a currency
-- switcher on the expense form (GBP or PKR at entry time).
--
-- `amount` on `expenses` stays what it always was — the GBP value used
-- everywhere else in the app (reports, summaries). `input_currency` /
-- `input_amount` record what the user actually typed, so an edit screen
-- can show "15000 PKR" back to them instead of a converted GBP figure
-- they never entered. When input_currency = 'GBP', input_amount always
-- equals amount.

ALTER TABLE expenses
    ADD COLUMN IF NOT EXISTS input_currency CHAR(3) NOT NULL DEFAULT 'GBP' AFTER currency,
    ADD COLUMN IF NOT EXISTS input_amount DECIMAL(14,2) NULL AFTER input_currency,
    ADD COLUMN IF NOT EXISTS updated_by VARCHAR(190) NULL AFTER updated_at;

-- Backfill existing rows: they were all entered in GBP (see FinanceService
-- docblock), so input_currency/input_amount just mirror the existing amount.
UPDATE expenses
    SET input_currency = 'GBP', input_amount = amount
    WHERE input_amount IS NULL;

ALTER TABLE income
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN IF NOT EXISTS updated_by VARCHAR(190) NULL AFTER updated_at;
