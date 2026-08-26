# Lead Capture API

The CRM exposes a server-to-server endpoint for British Mobile Tyres website integrations:

`POST https://ads.britishmobiletyres.co.uk/api/leads.php`

## Authentication

Send the `LEAD_API_KEY` configured on the CRM server in:

`X-BMT-LEAD-KEY: your-secret-key`

Never expose this key in browser JavaScript. The main website should call the CRM from server-side PHP or another protected backend.

## Example payload

```json
{
  "lead": {
    "lead_type": "whatsapp",
    "source": "google_ads",
    "customer_name": "Example Customer",
    "customer_phone": "+447700900000",
    "service_requested": "Mobile tyre replacement",
    "city": "Manchester",
    "postcode": "M1 1AA"
  },
  "attribution": {
    "gclid": "captured-google-click-id",
    "gbraid": null,
    "wbraid": null,
    "landing_page": "https://britishmobiletyres.co.uk/...",
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "bmt-search"
  }
}
```

## Response

Successful requests return HTTP `201` and a permanent CRM lead ID. Store that ID with the originating website enquiry so future booking and revenue updates can be linked to the same customer journey.

## Privacy

Only send business information needed to manage the enquiry and attribution. Do not send API keys, Google OAuth credentials or other secrets through the lead payload.
