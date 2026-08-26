# British Mobile Tyre CRM

Private CRM and Google Ads automation platform for British Mobile Tyres.

## Version 0.1.0 foundation

This repository starts the production-oriented foundation for:

- Lead capture and lifecycle management
- Google Ads attribution fields
- Conversion tracking records
- Campaign and city configuration
- Approval-controlled automation
- Immutable audit logging and reversible changes
- Daily scheduled jobs for Hostinger Web Hosting
- Private dashboard at `ads.britishmobiletyres.co.uk`

## Planned stack

- PHP 8.2+ / 8.3 compatible
- MySQL/MariaDB
- Composer
- Google Ads API PHP client
- Hostinger Cron Jobs
- Plain server-rendered private dashboard

## Safety model

No Google Ads mutation is allowed without an audit record. Major actions require approval. Secrets must never be committed to Git.

## Repository status

Foundation build in progress. The system must run in audit-only mode before any live Google Ads automation is enabled.
