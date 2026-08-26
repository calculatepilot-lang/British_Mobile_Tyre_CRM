# Hostinger Deployment Plan

Target: `ads.britishmobiletyres.co.uk`

## Do not deploy yet

This repository is intentionally in foundation and audit-only mode. Do not place Google credentials in GitHub.

## Production prerequisites

1. Create a MySQL database and restricted database user in Hostinger hPanel.
2. Copy `.env.example` to `.env` outside the public document root where possible.
3. Set the subdomain document root to the repository `public/` directory, or use an equivalent safe deployment layout.
4. Enable SSL for `ads.britishmobiletyres.co.uk`.
5. Enable SSH and run `composer install --no-dev --optimize-autoloader`.
6. Import `database/schema.sql`.
7. Verify the application in audit-only mode.
8. Configure cron jobs only after verifying their exact server paths.

## Recommended initial cron jobs

- Daily audit: target 04:00 Europe/London
- Budget guard: target 12:00 Europe/London
- Evening monitoring: target 20:00 Europe/London

The exact cron expression must be adjusted to the hosting server timezone and verified in hPanel before activation.

## Security

Never commit `.env`, OAuth secrets, refresh tokens, Google Ads developer tokens or database passwords.
