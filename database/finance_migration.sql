-- BMT Phase 1 finance module. Run after database/schema.sql and database/production_migration.sql.

ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS earned_gbp DECIMAL(14,2) NULL AFTER final_revenue,
    ADD COLUMN IF NOT EXISTS converted_at DATETIME NULL AFTER earned_gbp,
    ADD COLUMN IF NOT EXISTS earned_pkr_rate DECIMAL(18,8) NULL,
    ADD COLUMN IF NOT EXISTS earned_pkr_rate_source VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS earned_pkr_rate_locked_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS earned_pkr_gross DECIMAL(16,2) NULL,
    ADD COLUMN IF NOT EXISTS earned_conversion_tax_pkr DECIMAL(14,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS earned_pkr_net DECIMAL(16,2) NULL,
    ADD INDEX IF NOT EXISTS idx_leads_converted_at (converted_at);

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    exchange_rate_to_pkr DECIMAL(18,8) NULL,
    rate_source VARCHAR(255) NULL,
    rate_locked_at DATETIME NULL,
    converted_amount_pkr DECIMAL(16,2) NULL,
    tax_amount_pkr DECIMAL(14,2) NOT NULL DEFAULT 0,
    supplier VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    import_batch_id CHAR(36) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_currency_date (currency, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_exchange_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_currency CHAR(3) NOT NULL,
    quote_currency CHAR(3) NOT NULL,
    rate DECIMAL(18,8) NOT NULL,
    source VARCHAR(255) NOT NULL,
    fetched_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_rate_snapshot (base_currency, quote_currency, fetched_at),
    INDEX idx_rate_pair_time (base_currency, quote_currency, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_imports (
    id CHAR(36) PRIMARY KEY,
    source_type ENUM('csv','xlsx','pdf','manual') NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    status ENUM('uploaded','processing','completed','failed','needs_review') NOT NULL DEFAULT 'uploaded',
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
    rows_rejected INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_finance_import_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
