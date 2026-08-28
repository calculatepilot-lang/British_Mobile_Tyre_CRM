CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','staff','viewer') NOT NULL DEFAULT 'staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(40) NOT NULL UNIQUE,
    status ENUM('new','contacted','qualified','quoted','booked','completed','lost','spam','duplicate','existing_customer') NOT NULL DEFAULT 'new',
    source VARCHAR(50) NOT NULL DEFAULT 'direct',
    lead_type ENUM('phone','whatsapp','form','purchase','other') NOT NULL,
    customer_name VARCHAR(150) NULL,
    customer_phone VARCHAR(50) NULL,
    customer_email VARCHAR(190) NULL,
    service_requested VARCHAR(190) NULL,
    city VARCHAR(120) NULL,
    postcode VARCHAR(20) NULL,
    language VARCHAR(10) NULL DEFAULT 'en',
    quoted_amount DECIMAL(12,2) NULL,
    final_revenue DECIMAL(12,2) NULL,
    outcome_reason VARCHAR(255) NULL,
    quality_score TINYINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leads_status_created (status, created_at),
    INDEX idx_leads_city (city),
    INDEX idx_leads_source_created (source, created_at),
    INDEX idx_leads_phone_created (customer_phone, created_at),
    INDEX idx_leads_email_created (customer_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_attribution (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    gclid VARCHAR(255) NULL,
    gbraid VARCHAR(255) NULL,
    wbraid VARCHAR(255) NULL,
    campaign_id VARCHAR(64) NULL,
    campaign_name VARCHAR(255) NULL,
    ad_group_id VARCHAR(64) NULL,
    ad_group_name VARCHAR(255) NULL,
    keyword_text VARCHAR(255) NULL,
    match_type VARCHAR(40) NULL,
    landing_page TEXT NULL,
    utm_source VARCHAR(100) NULL,
    utm_medium VARCHAR(100) NULL,
    utm_campaign VARCHAR(255) NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attribution_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_attr_gclid (gclid),
    INDEX idx_attr_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_data JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_events_lead_created (lead_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_uuid CHAR(36) NOT NULL UNIQUE,
    change_type VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_name VARCHAR(255) NULL,
    resource_id VARCHAR(255) NULL,
    reason TEXT NOT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('planned','pending_approval','executed','rejected','rolled_back','failed') NOT NULL DEFAULT 'planned',
    reversible TINYINT(1) NOT NULL DEFAULT 1,
    review_note TEXT NULL,
    approved_by VARCHAR(190) NULL,
    approved_at DATETIME NULL,
    executed_at DATETIME NULL,
    rolled_back_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_changes_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_date DATE NOT NULL,
    scope_type ENUM('account','campaign','ad_group','city','keyword') NOT NULL,
    scope_id VARCHAR(100) NOT NULL,
    scope_name VARCHAR(255) NULL,
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
    conversions DECIMAL(12,4) NOT NULL DEFAULT 0,
    conversion_value DECIMAL(14,4) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_metric_scope (metric_date, scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS error_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    context VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_error_logs_context_created (context, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
