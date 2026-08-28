-- Run after database/schema.sql. Safe additive production migration.

ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS vehicle_type ENUM('car','van','caravan','bus','truck','trailer') NULL AFTER service_requested,
    ADD INDEX IF NOT EXISTS idx_leads_vehicle_type (vehicle_type);

ALTER TABLE automation_changes
    ADD COLUMN IF NOT EXISTS review_note TEXT NULL AFTER reversible;

-- NOTE: automation_decisions was removed here. It duplicated automation_changes
-- (defined in schema.sql) with an incompatible column set. automation_changes,
-- written via BMT\Approvals\ApprovalService, is the single canonical table for
-- all automation proposal/approval tracking (conversion actions included).

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
