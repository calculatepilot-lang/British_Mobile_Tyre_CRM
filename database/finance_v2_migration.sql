-- BMT CRM — Finance module v2 migration (revised)
-- Run AFTER database/finance_migration.sql (v1), which creates the base
-- `expenses` table this migration builds on.
--
-- v1's `expenses` table already has everything the finance model needs:
-- amount, currency, exchange_rate_to_pkr, converted_amount_pkr,
-- rate_locked_at, supplier. This migration does NOT rename or duplicate
-- any of those — it only adds:
--   1. expense_categories — user-definable categories (category_id FK)
--   2. exchange_rate_log — permanent audit trail of every fetched rate
--   3. income — a proper income table, independent of leads
--   4. expenses.category_id — links each expense to expense_categories
--
-- Safe to re-run — uses IF NOT EXISTS / seed checks throughout.
--   mysql -u <user> -p <database> < database/finance_migration.sql       (v1, if not already run)
--   mysql -u <user> -p <database> < database/finance_v2_migration.sql    (this file)

-- 1. User-definable expense categories (not hardcoded in PHP)
CREATE TABLE IF NOT EXISTS expense_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    archived    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO expense_categories (name, is_default) VALUES
    ('Payment to Suhaib', 1),
    ('Payment to Faiz', 1),
    ('Google Ads Spend', 1),
    ('Hosting & Software', 1),
    ('Other', 1);

-- 2. Exchange rate audit log — every rate ever fetched, kept permanently.
-- Separate from v1's per-transaction exchange_rate_to_pkr/rate_locked_at
-- (which record the rate a SPECIFIC expense locked); this table is the
-- broader history of every rate the app has ever fetched.
CREATE TABLE IF NOT EXISTS exchange_rate_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    base_currency  VARCHAR(3) NOT NULL DEFAULT 'GBP',
    quote_currency VARCHAR(3) NOT NULL DEFAULT 'PKR',
    rate        DECIMAL(14,6) NOT NULL,
    source      VARCHAR(60)  NOT NULL DEFAULT 'open.er-api.com',
    fetched_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pair_fetched (base_currency, quote_currency, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Income table — money in, independent of the leads table so manual /
-- non-lead income can be recorded too. Lead-derived earnings (leads.earned_gbp,
-- added by v1) are still counted separately in reporting.
CREATE TABLE IF NOT EXISTS income (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    source       VARCHAR(40)  NOT NULL DEFAULT 'manual',
    lead_id      INT NULL,
    description  VARCHAR(255) NULL,
    amount_gbp   DECIMAL(12,2) NOT NULL,
    received_at  DATE NOT NULL,
    created_by   VARCHAR(190) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Link expenses to expense_categories, in addition to (not replacing)
-- v1's free-text `category` VARCHAR column. `category` stays as-is for
-- backward compatibility; category_id is the new structured link.
ALTER TABLE expenses
    ADD COLUMN IF NOT EXISTS category_id INT NULL AFTER category;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'expenses'
    AND CONSTRAINT_NAME = 'fk_expenses_category'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE expenses ADD CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL',
    'SELECT "fk_expenses_category already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
