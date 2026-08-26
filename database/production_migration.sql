-- Run after database/schema.sql. Safe additive production migration.

ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS vehicle_type ENUM('car','van','caravan','bus','truck','trailer') NULL AFTER service_requested,
    ADD INDEX IF NOT EXISTS idx_leads_vehicle_type (vehicle_type);

CREATE TABLE IF NOT EXISTS automation_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    decision_type VARCHAR(100) NOT NULL,
    resource_name VARCHAR(255) NOT NULL,
    status ENUM('planned','pending_approval','approved','rejected','executed','failed','rolled_back') NOT NULL DEFAULT 'planned',
    risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    reversible TINYINT(1) NOT NULL DEFAULT 1,
    before_state JSON NULL,
    proposed_state JSON NULL,
    review_note TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_decisions_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_campaign_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_date DATE NOT NULL,
    campaign_id VARCHAR(100) NULL,
    campaign_name VARCHAR(255) NOT NULL,
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
    conversions DECIMAL(14,4) NOT NULL DEFAULT 0,
    conversion_value DECIMAL(16,4) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_daily_campaign (metric_date, campaign_name),
    INDEX idx_campaign_metrics_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(40) NOT NULL,
    notification_type VARCHAR(100) NOT NULL,
    status ENUM('queued','sent','failed') NOT NULL,
    external_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
