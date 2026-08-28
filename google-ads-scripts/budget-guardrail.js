/**
 * British Mobile Tyres — Daily Budget Guardrail
 *
 * Runs natively inside Google Ads (Tools & Settings > Bulk Actions > Scripts),
 * independent of the CRM server. Intended schedule: hourly, every day.
 *
 * What it does:
 *   For every enabled campaign, compares today's spend-so-far against a
 *   per-campaign daily cap (below). If a campaign's spend exceeds its cap,
 *   the script PAUSES it immediately and emails a notification. This is a
 *   pure safety net — it can only pause a campaign that is overspending,
 *   never create, unpause, or otherwise alter budgets/bids. Every action is
 *   also written to the CRM's automation_changes table via the existing
 *   lead intake style API, so it shows up on /changes exactly like the
 *   PHP-side proposals, keeping the two systems in one review history.
 *
 * What it deliberately does NOT do:
 *   - It never unpauses anything. Re-enabling a paused campaign is a
 *     decision for a person, not this script.
 *   - It never changes budget amounts or bids — only campaign status.
 *   - It never creates campaigns, ad groups, keywords, or ads.
 *
 * Setup:
 *   1. In Google Ads, go to Tools & Settings > Bulk Actions > Scripts.
 *   2. Click the blue "+" button, paste this entire file, name it
 *      "Daily Budget Guardrail".
 *   3. Update DAILY_CAPS below (or leave campaigns out of the map to use
 *      DEFAULT_DAILY_CAP_PKR for all of them). Figures are in the account's
 *      own billing currency — PKR for this account, since that's what
 *      campaign.getStatsFor().getCost() returns for a PKR-currency account.
 *   4. Update NOTIFICATION_EMAIL and (optionally) CRM_LOG_ENDPOINT / CRM_LOG_KEY.
 *   5. Click "Preview" first to confirm it targets the campaigns you expect
 *      with no unintended pauses, then authorize and schedule hourly.
 */

// ---- Configuration ----

var DEFAULT_DAILY_CAP_PKR = 5700; // ≈ £15 at ~380 PKR/GBP — review and adjust to your real target.

// Optional per-campaign overrides. Campaign name must match exactly.
var DAILY_CAPS = {
  // 'BMT | Search | London': 30,
  // 'BMT | Search | Manchester': 20,
};

var NOTIFICATION_EMAIL = 'calculatepilot@gmail.com';

// Optional: also log the pause to the CRM so it appears on /changes.
// Leave CRM_LOG_ENDPOINT blank to skip this and rely on email only.
var CRM_LOG_ENDPOINT = 'https://ads.britishmobiletyres.co.uk/api/automation-events.php';
var CRM_LOG_KEY = '457f1ba532d9415b67b3751f7ff37631cee93aab82aee0ebf7540dd992c98df5'; // Matches LEAD_API_KEY in the CRM's .env.

// ---- Script body ----

function main() {
  var pausedCampaigns = [];
  var campaignIterator = AdsApp.campaigns()
    .withCondition('Status = ENABLED')
    .get();

  while (campaignIterator.hasNext()) {
    var campaign = campaignIterator.next();
    var name = campaign.getName();
    var cap = DAILY_CAPS.hasOwnProperty(name) ? DAILY_CAPS[name] : DEFAULT_DAILY_CAP_PKR;

    var stats = campaign.getStatsFor('TODAY');
    var spend = stats.getCost();

    if (spend > cap) {
      campaign.pause();
      pausedCampaigns.push({ name: name, spend: spend, cap: cap });
      logToCrm(name, spend, cap);
    }
  }

  if (pausedCampaigns.length > 0) {
    notify(pausedCampaigns);
  } else {
    Logger.log('Budget guardrail: all campaigns within their daily cap.');
  }
}

function notify(paused) {
  var lines = paused.map(function (p) {
    return '- ' + p.name + ': spent PKR ' + p.spend.toFixed(2) + ' (cap PKR ' + p.cap.toFixed(2) + ') — PAUSED';
  });

  var body = 'The following campaign(s) exceeded their daily budget cap and were automatically paused:\n\n'
    + lines.join('\n')
    + '\n\nReview in Google Ads and re-enable manually once you\'ve confirmed the cause '
    + '(e.g. a traffic spike worth funding further, vs. a tracking or targeting issue worth fixing first).';

  MailApp.sendEmail(NOTIFICATION_EMAIL, 'BMT Ads: campaign(s) auto-paused on budget cap', body);
  Logger.log(body);
}

function logToCrm(campaignName, spend, cap) {
  if (!CRM_LOG_ENDPOINT || !CRM_LOG_KEY) {
    return;
  }

  try {
    UrlFetchApp.fetch(CRM_LOG_ENDPOINT, {
      method: 'post',
      contentType: 'application/json',
      headers: { 'X-BMT-LEAD-KEY': CRM_LOG_KEY },
      payload: JSON.stringify({
        change_type: 'pause_campaign',
        resource_type: 'google_ads_campaign',
        resource_name: campaignName,
        reason: 'Ads Script budget guardrail: spend PKR ' + spend.toFixed(2) + ' exceeded cap PKR ' + cap.toFixed(2) + ' for today.',
        risk_level: 'high',
        status: 'executed',
      }),
      muteHttpExceptions: true,
    });
  } catch (e) {
    Logger.log('CRM logging failed (non-fatal): ' + e);
  }
}
