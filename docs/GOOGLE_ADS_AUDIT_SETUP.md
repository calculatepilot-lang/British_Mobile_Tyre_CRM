# Google Ads audit-only setup

## Safety first

The initial integration is read-only. Set:

```env
AUTOMATION_MODE=audit_only
```

Do not enable mutation workflows until account audit results, conversion ownership, campaign structure, and budget rules have been reviewed.

## Required environment variables

```env
GOOGLE_ADS_DEVELOPER_TOKEN=
GOOGLE_ADS_CLIENT_ID=
GOOGLE_ADS_CLIENT_SECRET=
GOOGLE_ADS_REFRESH_TOKEN=
GOOGLE_ADS_CUSTOMER_ID=
GOOGLE_ADS_LOGIN_CUSTOMER_ID=
```

`GOOGLE_ADS_CUSTOMER_ID` is the Ads account to inspect. `GOOGLE_ADS_LOGIN_CUSTOMER_ID` is required when access is through a manager account; otherwise leave it empty.

Never commit real values to GitHub. Copy `.env.example` to `.env` on the server and restrict file permissions.

## First audit

After Composer dependencies and the database schema are installed:

```bash
php cron/daily_audit.php
```

The job audits the account and conversion actions, then stores previous-day campaign metrics in `daily_metrics`. It does not create, update, pause, or remove Google Ads resources.

## Conversion ownership

The audit reads the account conversion-tracking setting and reports the conversion customer. This must be reviewed before any future conversion-action creation because cross-account conversion tracking can use a different customer for conversion management.

## Before enabling write operations

Review all of the following:

1. Google Ads developer token access and account permissions.
2. Conversion customer and existing conversion actions.
3. Duplicate conversion risks.
4. Daily budget limit in `MAX_DAILY_BUDGET_GBP`.
5. Service cities in `config/cities.php`.
6. Approval workflow and rollback snapshots.
7. At least several days of audit-only data.

No live mutation workflow is included in the current phase.
