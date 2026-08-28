<?php

return [
    'mode' => getenv('AUTOMATION_MODE') ?: 'audit_only',
    // Budget/spend figures throughout this app are in the Google Ads account's
    // own billing currency (PKR for this account) — Google Ads always expects
    // and reports amounts in the account currency, never a fixed currency like
    // GBP. Business revenue (leads.final_revenue etc.) is separately tracked
    // in GBP, since that's what UK customers are actually charged.
    'max_daily_budget' => (float) (getenv('MAX_DAILY_BUDGET') ?: 0),
    'max_auto_budget_change_percent' => (float) (getenv('MAX_AUTO_BUDGET_CHANGE_PERCENT') ?: 10),
    'max_auto_bid_change_percent' => (float) (getenv('MAX_AUTO_BID_CHANGE_PERCENT') ?: 10),
    'major_change_approval_required' => filter_var(getenv('MAJOR_CHANGE_APPROVAL_REQUIRED') ?: 'true', FILTER_VALIDATE_BOOL),
    'minimum_clicks_for_decision' => 30,
    'minimum_conversions_for_scale' => 3,
    'pause_requires_approval' => true,
];
