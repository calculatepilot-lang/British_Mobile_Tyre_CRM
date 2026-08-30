# British Mobile Tyre CRM

Private CRM, finance tracking, and controlled Google Ads automation platform
for British Mobile Tyres.

## What's built

### CRM
- Secure PHP session authentication with CSRF protection, one-time admin
  setup via a server-side token
- Lead list, lead detail, manual lead creation
- Lead lifecycle: new → contacted → qualified → quoted → booked →
  completed / lost / spam / duplicate / existing customer
- Quote, completed revenue, and lead quality score recording
- Google Ads attribution (GCLID, GBRAID, WBRAID, UTM), multi-language
  capture, deduplication
- Lead event history

### Google Ads automation
- Read-side account audit and daily performance reporting
- Programmatic Search campaign planning across 61 UK cities (vehicle
  eligibility enforced: Car/Van/Caravan/Bus/Truck/Trailer only)
- Conversion action planning
- Rule-based daily optimisation (`OptimiserService`) — fixed thresholds on
  clicks/conversions/CPA decide budget increase/decrease/pause proposals
- **Claude-based daily optimisation** (`ClaudeDailyOptimiser`, optional,
  disabled by default) — a second, LLM-reasoned source of the same kind of
  proposal, validated against real campaign data and capped to the same
  percent limits as the rule engine. See `docs/CLAUDE_OPTIMISATION.md`.
- **Live execution** (`ChangeExecutor`) — once a proposal is approved on
  `/changes`:
  - Conversion actions are created directly in Google Ads (can run
    unattended on a schedule via `cron/execute_conversion_actions.php`,
    since a new conversion action can't spend anything by itself)
  - Campaign **skeletons** are created (budget + campaign + one ad group +
    proximity location targeting) — always **PAUSED**, with no keywords,
    ads, or negative keywords added automatically. A human finishes the
    campaign and switches it to ENABLED themselves.
  - Budget changes and campaign pauses require a human to click "Run
    approved changes" on `/changes` — these carry real spend risk, so a
    person chooses the exact moment they take effect
- A standalone Google Ads Script (`budget-guardrail.js`) as an independent
  safety net, separate from the PHP-side automation
- Approval workflow: every automated proposal is logged with before/after
  state for reversibility; nothing reaches Google Ads without a human
  approval step first, and no AI-sourced proposal (Claude) can skip that
  step regardless of its own confidence

### Finance
- Expense and income tracking in GBP, with the GBP→PKR exchange rate
  locked at the moment each transaction is entered (not recalculated
  later at a different rate)
- User-defined expense categories
- CSV import for bank/card statement exports — flexible header matching,
  per-row validation, permanent import history log
- Overall report: lifetime earned/spent/profit, spending by category with
  % share, and a 12-month trend table

## Stack

- PHP 8.2+
- MySQL/MariaDB
- Composer
- Google Ads API PHP client (v25)
- Anthropic API (optional — Claude-based optimisation only)
- Hostinger Cron Jobs
- Plain server-rendered dashboard (no frontend framework)

## Local or server setup

1. Copy `.env.example` to `.env` outside version control.
2. Create the MySQL database and run, in order:
   - `database/schema.sql`
   - `database/finance_migration.sql`
   - `database/finance_v2_migration.sql`
   - `database/production_migration.sql` (if applicable)
3. Run `composer install --no-dev --optimize-autoloader`.
4. Point the web root for `ads.britishmobiletyres.co.uk` to `public/`.
5. Set a long random `INITIAL_SETUP_TOKEN` in `.env`.
6. Visit `/setup` once and create the first administrator.
7. Leave `AUTOMATION_MODE=audit_only` until you've reviewed the automation
   docs below and are ready to start approving proposals.

## Automation docs

- `docs/GOOGLE_ADS_AUDIT_SETUP.md` — audit setup and the conversion-action
  scheduled executor
- `docs/CAMPAIGN_PLANNING.md` — campaign proposal + skeleton creation
- `docs/CLAUDE_OPTIMISATION.md` — enabling and safety model for the
  Claude-based optimisation pass
- `docs/FINANCE_MODULE.md` — Finance module install/usage

## Safety model

- Nothing in this codebase moves money or changes live Google Ads
  targeting without an explicit human approval step on `/changes`.
- Campaigns created by the automation are always **PAUSED** and are never
  switched to ENABLED by any code path — that's a manual, deliberate
  action.
- Every executed change is logged with its before-state for reversibility.
- Secrets must never be committed to Git.
