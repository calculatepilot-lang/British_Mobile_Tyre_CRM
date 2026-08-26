<?php

return [
    'mode' => getenv('AUTOMATION_MODE') ?: 'audit_only',
    'max_daily_budget_gbp' => (float) (getenv('MAX_DAILY_BUDGET_GBP') ?: 0),
    'max_auto_budget_change_percent' => (float) (getenv('MAX_AUTO_BUDGET_CHANGE_PERCENT') ?: 10),
    'max_auto_bid_change_percent' => (float) (getenv('MAX_AUTO_BID_CHANGE_PERCENT') ?: 10),
    'major_change_approval_required' => filter_var(getenv('MAJOR_CHANGE_APPROVAL_REQUIRED') ?: 'true', FILTER_VALIDATE_BOOL),
    'minimum_clicks_for_decision' => 30,
    'minimum_conversions_for_scale' => 3,
    'pause_requires_approval' => true,
];
