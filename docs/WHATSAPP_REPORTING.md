# WhatsApp Daily Campaign Reporting

## Recommended integration

Use the official WhatsApp Business Platform (Cloud API) through a Meta business account. The CRM should not automate WhatsApp Web or use unofficial browser bots.

## Daily flow

1. Hostinger cron runs after daily Ads metrics are available.
2. CRM builds the previous-day report.
3. Report is rendered as an approved WhatsApp template with variables where required.
4. The official API sends the report to the configured opted-in recipient.
5. Delivery result is logged for audit and retry handling.

## Report contents

- Spend
- Impressions
- Clicks
- Google Ads conversions
- CRM leads
- Qualified leads
- Booked jobs
- Completed jobs
- Recorded revenue
- Optional optimiser summary and pending approvals

## Secrets

Keep the WhatsApp access token, phone number ID and recipient configuration in server environment variables, never in Git.

Suggested environment variables:

WHATSAPP_ENABLED=false
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_RECIPIENT=
WHATSAPP_TEMPLATE_NAME=bmt_daily_campaign_report
WHATSAPP_TEMPLATE_LANGUAGE=en_GB
