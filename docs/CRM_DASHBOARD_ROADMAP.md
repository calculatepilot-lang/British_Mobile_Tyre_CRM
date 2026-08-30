# CRM Dashboard Roadmap

## Purpose

The dashboard is the human approval layer for British Mobile Tyres CRM and Ads automation.

## Views

- Overview: leads, qualified leads, bookings, completed jobs and revenue.
- Leads: filter by city, vehicle type and status.
- Quality: compare city, vehicle and campaign outcomes.
- Campaign plans: view generated plans before any Google Ads mutation.
- Approvals: approve or reject pending changes.
- Change history: inspect before/after state and reversibility.

## Safety

Google Ads remains audit-only until a separate production activation review is completed. Approved budget/pause/campaign changes are planned records that still require a human to click "Run approved changes" — they are not executed merely because they were approved. The one exception: approved conversion-action creations can run unattended on a schedule (`cron/execute_conversion_actions.php`), since a new conversion action can't spend anything or change targeting by itself. See `docs/GOOGLE_ADS_AUDIT_SETUP.md` and `docs/CLAUDE_OPTIMISATION.md`.

## Vehicle policy

Only Car, Van, Caravan, Bus, Truck and Trailer are eligible.

## Targeting policy

61 UK cities are configured (expanded from the initial 40-city planning set) in `config/cities.php`. Road, motorway, major A-road, junction and service-area points require verified coordinates and review before activation. Live campaign creation uses simple proximity (radius) targeting around each city's verified centre point — it does not attempt any road-corridor targeting.
