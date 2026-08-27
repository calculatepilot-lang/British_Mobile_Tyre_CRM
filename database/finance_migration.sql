-- Finance module: realised earnings and expenses.

ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS earned_gbp DECIMAL(12,2) NULL AFTER final_revenue,
    ADD COLUMN IF NOT EXISTS converted_at DATETIME NULL AFTER earned_gbp,
    ADD INDEX IF NOT EXISTS idx_leads_converted_at (converted_at);

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    amount_gbp DECIMAL(12,2) NOT NULL,
    supplier VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    import_batch_id CHAR(36) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_category_date (category, expense_date)
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
