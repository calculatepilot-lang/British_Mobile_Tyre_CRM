-- Keyword-level metrics, needed for the CPC/bid optimizer. The existing
-- daily_campaign_metrics table only has campaign-level totals, which is
-- enough for budget decisions but not for deciding which individual
-- KEYWORDS deserve a higher or lower bid.

CREATE TABLE IF NOT EXISTS daily_keyword_metrics (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_date         DATE NOT NULL,
    campaign_id         VARCHAR(32) NOT NULL,
    campaign_name       VARCHAR(255) NOT NULL,
    ad_group_id         VARCHAR(32) NOT NULL,
    ad_group_name       VARCHAR(255) NOT NULL,
    criterion_id        VARCHAR(32) NOT NULL,
    criterion_resource_name VARCHAR(255) NOT NULL,
    keyword_text        VARCHAR(255) NOT NULL,
    match_type          VARCHAR(20) NOT NULL,
    current_cpc_bid_micros BIGINT NOT NULL DEFAULT 0,
    impressions         INT UNSIGNED NOT NULL DEFAULT 0,
    clicks              INT UNSIGNED NOT NULL DEFAULT 0,
    cost_micros         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    conversions         DECIMAL(10,2) NOT NULL DEFAULT 0,
    conversion_value     DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_date_criterion (metric_date, criterion_id),
    INDEX idx_date (metric_date),
    INDEX idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
