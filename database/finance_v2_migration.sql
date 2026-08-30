-- BMT CRM — Finance module v2 migration
-- Adds: user-definable expense categories, a proper income table (not just
-- lead-derived), GBP->PKR conversion fields locked at transaction time, and
-- an exchange-rate audit log. Safe to re-run — uses IF NOT EXISTS / seed
-- checks throughout. Run against the existing database:
--   mysql -u <user> -p <database> < database/finance_v2_migration.sql

-- 1. User-definable expense categories (not hardcoded in PHP)
CREATE TABLE IF NOT EXISTS expense_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    archived    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the categories named explicitly in the spec — safe to run repeatedly,
-- duplicates are silently skipped by the unique key.
INSERT IGNORE INTO expense_categories (name, is_default) VALUES
    ('Payment to Suhaib', 1),
    ('Payment to Faiz', 1),
    ('Google Ads Spend', 1),
    ('Hosting & Software', 1),
    ('Other', 1);

-- 2. Exchange rate audit log — every rate ever fetched, kept permanently.
-- A transaction's own locked rate (below) is the source of truth for that
-- transaction; this table is the broader audit trail of rate history.
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
-- non-lead income can be recorded too. Existing lead-derived earnings
-- (leads.earned_gbp) are still counted separately in reporting.
CREATE TABLE IF NOT EXISTS income (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    source       VARCHAR(40)  NOT NULL DEFAULT 'manual', -- 'manual' | 'lead' | 'other'
    lead_id      INT NULL,
    description  VARCHAR(255) NULL,
    amount_gbp   DECIMAL(12,2) NOT NULL,
    received_at  DATE NOT NULL,
    created_by   VARCHAR(190) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Extend the existing `expenses` table with category + PKR conversion
-- fields. Uses IF NOT EXISTS (MySQL 8.0.29+ / MariaDB 10.5+) so this is
-- safe even if some of these columns were partially added before.
ALTER TABLE expenses
    ADD COLUMN IF NOT EXISTS category_id      INT NULL AFTER amount_gbp,
    ADD COLUMN IF NOT EXISTS payee            VARCHAR(190) NULL AFTER category_id,
    ADD COLUMN IF NOT EXISTS amount_pkr       DECIMAL(14,2) NULL AFTER amount_gbp,
    ADD COLUMN IF NOT EXISTS exchange_rate    DECIMAL(14,6) NULL AFTER amount_pkr,
    ADD COLUMN IF NOT EXISTS rate_locked_at   DATETIME NULL AFTER exchange_rate,
    ADD COLUMN IF NOT EXISTS created_by       VARCHAR(190) NULL AFTER rate_locked_at,
    ADD COLUMN IF NOT EXISTS created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by;

-- Add the FK only if it doesn't already exist (MySQL has no clean
-- "ADD CONSTRAINT IF NOT EXISTS" — this guards it manually).
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
