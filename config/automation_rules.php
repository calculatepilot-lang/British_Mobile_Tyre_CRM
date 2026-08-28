<?php

function bmt_env(string $key, string $default = ''): string
{
    foreach ([
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        getenv($key) !== false ? getenv($key) : null,
    ] as $value) {
        if ($value !== null && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    return $default;
}

return [
    'mode' => bmt_env('AUTOMATION_MODE', 'audit_only'),
    'max_daily_budget_gbp' => (float) bmt_env('MAX_DAILY_BUDGET_GBP', '0'),
    'max_auto_budget_change_percent' => min(20.0, max(1.0, (float) bmt_env('MAX_AUTO_BUDGET_CHANGE_PERCENT', '10'))),
    'max_auto_bid_change_percent' => min(15.0, max(1.0, (float) bmt_env('MAX_AUTO_BID_CHANGE_PERCENT', '10'))),
    'major_change_approval_required' => filter_var(bmt_env('MAJOR_CHANGE_APPROVAL_REQUIRED', 'true'), FILTER_VALIDATE_BOOL),
    'minimum_clicks_for_decision' => max(30, (int) bmt_env('MINIMUM_CLICKS_FOR_DECISION', '30')),
    'minimum_conversions_for_scale' => max(3, (float) bmt_env('MINIMUM_CONVERSIONS_FOR_SCALE', '3')),
    'minimum_spend_before_pause_gbp' => max(20.0, (float) bmt_env('MINIMUM_SPEND_BEFORE_PAUSE_GBP', '50')),
    'pause_requires_approval' => true,
    'campaign_creation_requires_approval' => true,
    'conversion_creation_requires_approval' => true,
    'budget_reallocation_requires_approval' => true,
    'auto_execute_low_risk_bids' => filter_var(bmt_env('AUTO_EXECUTE_LOW_RISK_BIDS', 'true'), FILTER_VALIDATE_BOOL),
    'auto_execute_low_risk_budget_changes' => filter_var(bmt_env('AUTO_EXECUTE_LOW_RISK_BUDGET_CHANGES', 'false'), FILTER_VALIDATE_BOOL),
    'daily_execution_lock_minutes' => 30,
];
