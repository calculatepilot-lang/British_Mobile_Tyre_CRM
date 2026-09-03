<?php

// getenv() alone is unreliable on some PHP-FPM setups: values set via
// putenv() (which is how vlucas/phpdotenv applies .env by default) can
// persist stale across requests within the same long-lived worker process,
// so an updated .env file may not be reflected until that worker recycles.
// $_ENV and $_SERVER are populated fresh per request and don't have this
// problem — check those first, matching the pattern already used in
// app/Database.php, and only fall back to getenv() if neither has it.
if (!function_exists('bmt_env')) {
    function bmt_env(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        $value = getenv($key);

        return ($value !== false && $value !== '') ? (string) $value : $default;
    }
}

return [
    'mode' => bmt_env('AUTOMATION_MODE', 'audit_only'),
    // Budget/spend figures throughout this app are in the Google Ads account's
    // own billing currency (PKR for this account) — Google Ads always expects
    // and reports amounts in the account currency, never a fixed currency like
    // GBP. Business revenue (leads.final_revenue etc.) is separately tracked
    // in GBP, since that's what UK customers are actually charged.
    'max_daily_budget' => (float) bmt_env('MAX_DAILY_BUDGET', '0'),
    'max_auto_budget_change_percent' => (float) bmt_env('MAX_AUTO_BUDGET_CHANGE_PERCENT', '10'),
    'max_auto_bid_change_percent' => (float) bmt_env('MAX_AUTO_BID_CHANGE_PERCENT', '10'),
    'major_change_approval_required' => filter_var(bmt_env('MAJOR_CHANGE_APPROVAL_REQUIRED', 'true'), FILTER_VALIDATE_BOOL),
    'minimum_clicks_for_decision' => 30,
    'minimum_conversions_for_scale' => 3,
    'pause_requires_approval' => true,
];
