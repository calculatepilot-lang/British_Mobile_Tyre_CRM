# British Mobile Tyre CRM

Private CRM and controlled Google Ads automation platform for British Mobile Tyres.

## Current build: CRM MVP

The repository now includes:

- Secure PHP session authentication with CSRF protection
- One-time initial administrator setup protected by a server-side token
- CRM dashboard
- Lead list, lead detail and manual lead creation
- Lead lifecycle management: new, contacted, qualified, quoted, booked, completed, lost, spam, duplicate and existing customer
- Quote, completed revenue and lead quality score recording
- Google Ads attribution fields including GCLID, GBRAID, WBRAID and UTM values
- Lead event history foundation
- Automation change/audit table
- Daily metrics table
- Apache routing rules for Hostinger deployments
- Hostinger-compatible PHP architecture
- Google Ads automation remains `audit_only`

## Stack

- PHP 8.2+
- MySQL/MariaDB
- Composer
- Google Ads API PHP client
- Hostinger Cron Jobs
- Plain server-rendered private dashboard

## Local or server setup

1. Copy `.env.example` to `.env` outside version control.
2. Create the MySQL database and run `database/schema.sql`.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Point the web root for `ads.britishmobiletyres.co.uk` to `public/`.
5. Set a long random `INITIAL_SETUP_TOKEN` in `.env`.
6. Visit `/setup` once and create the first administrator.
7. Keep `AUTOMATION_MODE=audit_only` until Google Ads account auditing and approval controls are complete.

## Safety model

No live Google Ads mutation is enabled in the current build. The next integration phase will add read-only Google Ads account auditing first, followed by conversion planning and approval-controlled changes. Secrets must never be committed to Git.
