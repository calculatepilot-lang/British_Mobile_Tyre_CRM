# Production Readiness Checklist

## Pre-deployment

- [ ] Run `composer install --no-dev --optimize-autoloader`.
- [ ] Create database and run `database/schema.sql` followed by `database/production_migration.sql`.
- [ ] Configure `.env` outside Git and set `APP_DEBUG=false`.
- [ ] Set a unique `APP_KEY`, `INITIAL_SETUP_TOKEN` and `LEAD_API_KEY`.
- [ ] Confirm PHP extensions: PDO MySQL, curl, mbstring, JSON and OpenSSL.
- [ ] Point `ads.britishmobiletyres.co.uk` document root to `public/`.
- [ ] Force HTTPS and verify no `.env` file is web-accessible.

## Google Ads

- [ ] Keep `AUTOMATION_MODE=audit_only` initially.
- [ ] Add API credentials only after server deployment.
- [ ] Audit customer ID and existing conversion actions before proposing new actions.
- [ ] Verify call, click-to-call, WhatsApp and lead-form tracking separately.
- [ ] Do not enable mutation mode until change logging and approval workflow are tested.

## WhatsApp reporting

- [ ] Configure official WhatsApp Cloud API credentials as server environment variables.
- [ ] Create/approve the required daily report template.
- [ ] Add Hostinger cron for `cron/send_daily_report.php` at 08:00 Europe/London.
- [ ] Verify recipient opt-in and test delivery.

## Optimiser

- [ ] Import daily campaign metrics.
- [ ] Confirm CRM attribution and final outcomes are being recorded.
- [ ] Review recommendations before enabling any execution capability.
- [ ] Keep major budget, bid, pause and targeting decisions approval-gated.

## Final acceptance test

- [ ] Create a test lead for each supported lead source.
- [ ] Confirm unsupported vehicle types cannot be qualified.
- [ ] Confirm city, campaign and vehicle reports load.
- [ ] Create and approve/reject a test automation decision.
- [ ] Run the daily report in dry-run/test mode.
- [ ] Confirm no credentials appear in Git or dashboard output.
