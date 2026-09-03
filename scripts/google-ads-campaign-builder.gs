/**
 * British Mobile Tyres — Single-Campaign Service x City Ad Group Builder
 * =======================================================================
 * Runs inside Google Ads Scripts (Tools & Settings > Bulk Actions > Scripts),
 * NOT on the CRM server — it executes on Google's own infrastructure with
 * direct access to your live Ads account via the global `AdsApp` object.
 *
 * IMPORTANT — verification caveat (read before running):
 * `AdsApp` only exists inside a live Google Ads account's Script runtime.
 * There is no way to install it as a package or execute it outside that
 * environment, so — unlike the PHP changes made to the CRM this session,
 * which were verified by actually installing the real Ads API library and
 * instantiating real objects — this script could NOT be executed or
 * verified end-to-end before being handed to you. It is written against
 * Google's documented, stable Ads Scripts method signatures, but:
 *   1. Run it in PREVIEW mode first (the "Preview" button in the Scripts
 *      editor simulates the run and shows what WOULD happen, including
 *      full Logger output, without creating anything for real).
 *   2. Methods flagged with a "VERIFY" comment below are the ones most
 *      likely to have shifted between Ads Scripts API versions — check
 *      them against https://developers.google.com/google-ads/scripts/docs/reference
 *      if Preview mode reports an error on that line.
 *
 * HIERARCHY
 * =========
 * 1 Campaign ("BMT | Search | All Services")
 *   -> Proximity location targeting, one circle per CRM city (NOT one
 *      campaign per city — this script deliberately keeps everything in
 *      a single campaign, per spec)
 *   -> 5 Services x N cities = one Ad Group each, named "{Service} - {City}"
 *        -> Keywords: Exact + Phrase match only (Service+City combinations)
 *        -> One Responsive Search Ad (headlines/descriptions generated
 *           per service+city, filtered to Google's length limits)
 *
 * The campaign is always left PAUSED after creation — nothing spends
 * until a human reviews it and enables it manually in the Ads UI, the
 * same safety pattern used throughout the rest of this project.
 */

// ============================================================================
// CONFIG — adjust these before running
// ============================================================================
var CONFIG = {
  CAMPAIGN_NAME: 'BMT | Search | All Services',
  DAILY_BUDGET: 5.0, // account currency, per day — TOTAL for this one campaign
  FINAL_URL: 'https://britishmobiletyres.co.uk/',

  // Same 5 services already used elsewhere in this project, for consistency.
  SERVICES: [
    'Mobile Tyre Repair',
    'Mobile Tyre Replacement',
    'Mobile Tyre Change',
    'Mobile Tyre Puncture Repair',
    'Mobile Tyre Fitting',
  ],

  // The CRM's read-only cities endpoint (see /api/cities.json in the CRM
  // repo). Requires CRM_API_TOKEN to be set in the CRM's .env and the same
  // value pasted below — this script authenticates with a shared token,
  // not a login session, since Ads Scripts can't hold cookies.
  CRM_API_URL: 'https://ads.britishmobiletyres.co.uk/api/cities.json',
  CRM_API_TOKEN: 'PASTE_THE_SAME_TOKEN_FROM_.ENV_HERE',

  PROXIMITY_RADIUS_KM: 15,
};

// ============================================================================
// ENTRY POINT
// ============================================================================
function main() {
  Logger.log('=== BMT Campaign Builder — starting ===');

  var cities;
  try {
    cities = fetchCitiesFromCrm_();
  } catch (e) {
    Logger.log('FATAL: could not fetch cities from CRM — aborting. ' + e);
    return;
  }

  if (!cities || cities.length === 0) {
    Logger.log('FATAL: CRM returned zero cities — aborting. Nothing created.');
    return;
  }
  Logger.log('Fetched ' + cities.length + ' cities from CRM.');

  var campaign;
  try {
    campaign = getOrCreateCampaign_(CONFIG.CAMPAIGN_NAME, CONFIG.DAILY_BUDGET);
  } catch (e) {
    Logger.log('FATAL: could not create/find campaign — aborting. ' + e);
    return;
  }
  if (!campaign) {
    Logger.log('FATAL: campaign is null after getOrCreateCampaign_ — aborting.');
    return;
  }

  applyCityTargeting_(campaign, cities);

  var stats = { created: 0, skipped: 0, failed: 0, copyRejected: 0 };

  for (var s = 0; s < CONFIG.SERVICES.length; s++) {
    var service = CONFIG.SERVICES[s];

    for (var c = 0; c < cities.length; c++) {
      var city = cities[c];
      var adGroupName = service + ' - ' + city.name;

      try {
        var result = createServiceCityAdGroup_(campaign, service, city.name, adGroupName);
        if (result === 'created') stats.created++;
        else if (result === 'skipped_exists') stats.skipped++;
        else if (result === 'copy_rejected') stats.copyRejected++;
      } catch (e) {
        stats.failed++;
        Logger.log('ERROR creating ad group "' + adGroupName + '": ' + e);
      }
    }
  }

  Logger.log('=== BMT Campaign Builder — finished ===');
  Logger.log(
    'Ad groups created: ' + stats.created +
    ' | already existed (skipped): ' + stats.skipped +
    ' | failed (API error): ' + stats.failed +
    ' | rejected (copy too long/insufficient): ' + stats.copyRejected
  );
  Logger.log('Campaign "' + CONFIG.CAMPAIGN_NAME + '" was created/left PAUSED. Review before enabling.');
}

// ============================================================================
// CRM INTEGRATION
// ============================================================================

/**
 * Fetches the live city list from the CRM's /api/cities.json endpoint.
 * Never hard-codes locations — if the CRM is unreachable or returns
 * malformed data, this throws so main() aborts cleanly rather than running
 * with a stale or partial list.
 */
function fetchCitiesFromCrm_() {
  var url = CONFIG.CRM_API_URL + '?token=' + encodeURIComponent(CONFIG.CRM_API_TOKEN);
  Logger.log('Fetching cities from CRM: ' + CONFIG.CRM_API_URL);

  var response;
  try {
    response = UrlFetchApp.fetch(url, { muteHttpExceptions: true });
  } catch (e) {
    throw new Error('Network error calling CRM API: ' + e);
  }

  var code = response.getResponseCode();
  if (code !== 200) {
    throw new Error('CRM API returned HTTP ' + code + ': ' + response.getContentText());
  }

  var body;
  try {
    body = JSON.parse(response.getContentText());
  } catch (e) {
    throw new Error('CRM API response was not valid JSON: ' + e);
  }

  if (!body || !Array.isArray(body.cities)) {
    throw new Error('CRM API response missing expected "cities" array.');
  }

  // Validate each city has the fields we need before trusting it downstream.
  var valid = [];
  for (var i = 0; i < body.cities.length; i++) {
    var c = body.cities[i];
    if (c && typeof c.name === 'string' && c.name.length > 0 &&
        typeof c.lat === 'number' && typeof c.lng === 'number') {
      valid.push(c);
    } else {
      Logger.log('WARNING: skipping malformed city entry at index ' + i + ': ' + JSON.stringify(c));
    }
  }
  return valid;
}

// ============================================================================
// CAMPAIGN
// ============================================================================

/**
 * Returns the existing campaign by exact name if one already exists
 * (making this script safe to re-run without creating duplicates), or
 * creates a new one — PAUSED, Search-only, Manual CPC — if not.
 */
function getOrCreateCampaign_(name, dailyBudget) {
  var iterator = AdsApp.campaigns()
    .withCondition('campaign.name = "' + name.replace(/"/g, '\\"') + '"')
    .get();

  if (iterator.hasNext()) {
    var existing = iterator.next();
    Logger.log('Campaign "' + name + '" already exists (id ' + existing.getId() + ') — reusing it, not creating a duplicate.');
    return existing;
  }

  Logger.log('Creating new campaign "' + name + '" with daily budget ' + dailyBudget + '.');

  // VERIFY: .withBudget() on newCampaignBuilder() sets a standard (non-
  // shared) budget of this amount per day, in the account's currency.
  // Confirm this is still the current method name/signature in Ads
  // Scripts' reference docs at the time you run this.
  var campaignOperation = AdsApp.newCampaignBuilder()
    .withName(name)
    .withBudget(dailyBudget)
    .withNetworks(['GOOGLE_SEARCH']) // Search only — never Display/Search partners
    .build();

  if (!campaignOperation.isSuccessful()) {
    throw new Error('Campaign creation failed: ' + JSON.stringify(campaignOperation.getErrors()));
  }

  var campaign = campaignOperation.getResult();

  // Always leave it PAUSED — nothing spends until a human reviews and
  // enables it manually. This mirrors the safety pattern used everywhere
  // else in this project.
  campaign.pause();
  Logger.log('Campaign "' + name + '" created (id ' + campaign.getId() + ') and set to PAUSED.');

  return campaign;
}

/**
 * Adds one proximity location-targeting circle per CRM city to the
 * campaign. Skips (logs, doesn't throw) any city that fails to add, so one
 * bad coordinate doesn't abort the whole targeting pass.
 */
function applyCityTargeting_(campaign, cities) {
  var added = 0;
  var failed = 0;

  for (var i = 0; i < cities.length; i++) {
    var city = cities[i];
    try {
      // VERIFY: addProximity(latitude, longitude, radius) — confirm the
      // radius unit (miles vs km) and whether a 4th unit argument is
      // required in the current Ads Scripts version. As documented at the
      // time this was written, the 3-argument form uses miles by default;
      // adjust PROXIMITY_RADIUS_KM/conversion below if that's changed.
      var radiusMiles = CONFIG.PROXIMITY_RADIUS_KM * 0.621371;
      campaign.addProximity(city.lat, city.lng, radiusMiles);
      added++;
    } catch (e) {
      failed++;
      Logger.log('WARNING: could not add location targeting for "' + city.name + '": ' + e);
    }
  }

  Logger.log('Location targeting: ' + added + ' cities added, ' + failed + ' failed.');
}

// ============================================================================
// AD GROUPS, KEYWORDS, ADS
// ============================================================================

/**
 * Creates one ad group for a single service+city combination: the ad
 * group itself, its keywords (Exact + Phrase), and one Responsive Search
 * Ad. Returns a status string so main() can tally outcomes:
 *   'created'        — ad group + keywords + ad all created successfully
 *   'skipped_exists' — an ad group with this exact name already exists
 *   'copy_rejected'  — not enough valid headlines/descriptions survived
 *                      length validation to build a compliant RSA; the ad
 *                      group itself may still have been created with
 *                      keywords but WITHOUT an ad — logged clearly either way
 */
function createServiceCityAdGroup_(campaign, service, cityName, adGroupName) {
  var existing = campaign.adGroups()
    .withCondition('ad_group.name = "' + adGroupName.replace(/"/g, '\\"') + '"')
    .get();
  if (existing.hasNext()) {
    Logger.log('Ad group "' + adGroupName + '" already exists — skipping.');
    return 'skipped_exists';
  }

  var adGroupOperation = campaign.newAdGroupBuilder()
    .withName(adGroupName)
    .withStatus('ENABLED') // harmless while the campaign itself is PAUSED
    .build();

  if (!adGroupOperation.isSuccessful()) {
    throw new Error('Ad group creation failed: ' + JSON.stringify(adGroupOperation.getErrors()));
  }
  var adGroup = adGroupOperation.getResult();
  Logger.log('Created ad group "' + adGroupName + '" (id ' + adGroup.getId() + ').');

  addKeywords_(adGroup, service, cityName);

  var copy = generateCompliantCopy_(service, cityName);
  if (copy.headlines.length < 3 || copy.descriptions.length < 2) {
    Logger.log(
      'REJECTED ad for "' + adGroupName + '": only ' + copy.headlines.length +
      ' valid headline(s) and ' + copy.descriptions.length +
      ' valid description(s) survived length validation (need >= 3 and >= 2). ' +
      'Ad group and keywords were still created — add copy manually for this one.'
    );
    return 'copy_rejected';
  }

  createResponsiveSearchAd_(adGroup, copy);
  return 'created';
}

/**
 * Adds Exact and Phrase match keywords for Service+City. Deliberately no
 * Broad match keywords are ever added, matching the CRM's own campaign
 * builder policy.
 */
function addKeywords_(adGroup, service, cityName) {
  var baseText = service + ' ' + cityName;
  var texts = [
    baseText,
    baseText + ' near me',
  ];

  for (var i = 0; i < texts.length; i++) {
    var text = texts[i];
    try {
      adGroup.newKeywordBuilder().withText('[' + text + ']').build(); // Exact
      adGroup.newKeywordBuilder().withText('"' + text + '"').build(); // Phrase
    } catch (e) {
      Logger.log('WARNING: failed to add keyword "' + text + '" to ad group "' + adGroup.getName() + '": ' + e);
    }
  }
}

/**
 * Builds one RSA on the ad group from already-validated headlines/
 * descriptions. Assumes generateCompliantCopy_() has already filtered the
 * arrays to Google's length limits and minimum-count requirements.
 */
function createResponsiveSearchAd_(adGroup, copy) {
  var adBuilder = adGroup.newAd().responsiveSearchAdBuilder()
    .withFinalUrl(CONFIG.FINAL_URL);

  for (var h = 0; h < copy.headlines.length; h++) {
    adBuilder = adBuilder.addHeadline(copy.headlines[h]);
  }
  for (var d = 0; d < copy.descriptions.length; d++) {
    adBuilder = adBuilder.addDescription(copy.descriptions[d]);
  }

  var adOperation = adBuilder.build();
  if (!adOperation.isSuccessful()) {
    Logger.log('WARNING: RSA creation failed for ad group "' + adGroup.getName() + '": ' + JSON.stringify(adOperation.getErrors()));
    return;
  }
  Logger.log('Created RSA for ad group "' + adGroup.getName() + '" with ' + copy.headlines.length + ' headlines, ' + copy.descriptions.length + ' descriptions.');
}

// ============================================================================
// COPY GENERATION + VALIDATION
// ============================================================================

var HEADLINE_MAX_LENGTH = 30;
var DESCRIPTION_MAX_LENGTH = 90;
var HEADLINE_MAX_COUNT = 15; // Google Ads RSA limit
var DESCRIPTION_MAX_COUNT = 4; // Google Ads RSA limit

/**
 * Generates headline/description candidates for a service+city pair, then
 * validates every single one against Google's character limits BEFORE
 * returning them. Anything oversized is REJECTED (dropped), never
 * truncated — a truncated headline can read as broken or misleading,
 * which is worse than simply having one fewer variant. Every rejection is
 * logged individually so nothing silently disappears without a trace.
 */
function generateCompliantCopy_(service, cityName) {
  var headlineCandidates = [
    service,
    service + ' ' + cityName,
    'We Come To You',
    '24/7 Mobile Fitting',
    'Book Online Today',
    'Same Day Service',
    'No Callout Fee',
    'Fully Mobile Fitters',
  ];

  var descriptionCandidates = [
    service + ' in ' + cityName + '. Book online today.',
    'We come to you in ' + cityName + ' — fast, reliable ' + service.toLowerCase() + '.',
    'Same day ' + service.toLowerCase() + ' across ' + cityName + '. Fully trained mobile fitters.',
  ];

  var headlines = validateCopyList_(headlineCandidates, HEADLINE_MAX_LENGTH, HEADLINE_MAX_COUNT, 'headline');
  var descriptions = validateCopyList_(descriptionCandidates, DESCRIPTION_MAX_LENGTH, DESCRIPTION_MAX_COUNT, 'description');

  return { headlines: headlines, descriptions: descriptions };
}

/**
 * Filters a list of candidate strings down to ones that pass the given
 * max length, capped at maxCount, logging every single rejection with its
 * actual length so a human can see exactly what was dropped and why.
 */
function validateCopyList_(candidates, maxLength, maxCount, label) {
  var valid = [];
  for (var i = 0; i < candidates.length; i++) {
    var text = candidates[i];
    if (typeof text !== 'string' || text.length === 0) {
      Logger.log('VALIDATION: empty/invalid ' + label + ' candidate skipped.');
      continue;
    }
    if (text.length > maxLength) {
      Logger.log(
        'VALIDATION REJECTED ' + label + ' (length ' + text.length +
        ' > max ' + maxLength + '): "' + text + '"'
      );
      continue;
    }
    valid.push(text);
    if (valid.length >= maxCount) break;
  }
  return valid;
}
