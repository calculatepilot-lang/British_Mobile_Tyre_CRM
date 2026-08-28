# Google Ads Scripts

These run natively **inside Google Ads** (Tools & Settings → Bulk Actions →
Scripts), not on the CRM server. They're independent of Hostinger uptime —
Google runs them on its own schedule.

## budget-guardrail.js

Hourly safety net: pauses any enabled campaign that has spent more than its
configured daily cap **today**, and emails a notification. Never unpauses,
never changes budgets/bids, never creates anything — the only action it can
take is pausing an overspending campaign.

**Setup:**
1. Google Ads → Tools & Settings → Bulk Actions → Scripts → "+"
2. Paste the full contents of `budget-guardrail.js`, name it "Daily Budget Guardrail"
3. Edit `DAILY_CAPS` / `DEFAULT_DAILY_CAP_GBP` and `NOTIFICATION_EMAIL` at the top
4. Click **Preview** first — confirm it targets the campaigns you expect before authorizing
5. Authorize, then schedule to run **hourly**

**Optional CRM integration:** set `CRM_LOG_KEY` to the same value as
`LEAD_API_KEY` in the CRM's `.env` to have every auto-pause also appear on
the CRM's `/changes` page. Leave it blank to rely on email only — the
script works either way.
