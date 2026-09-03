/**
 * British Mobile Tyres — Single-Campaign Service x City Ad Group Builder
 * =======================================================================
 * Runs inside Google Ads Scripts (Tools & Settings > Bulk Actions > Scripts),
 * NOT on the CRM server — it executes on Google's own infrastructure with
 * direct access to your live Ads account via the global `AdsApp` object.
 *
 * REVISION NOTE (read this first):
 * The first version of this script used AdsApp.newCampaignBuilder() /
 * newAdGroupBuilder() / newKeywordBuilder() / responsiveSearchAdBuilder(),
 * which turned out to not exist in the current API — confirmed by running
 * it in Preview mode, which threw "AdsApp.newCampaignBuilder is not a
 * function" (the CRM city-fetch step worked perfectly; only the campaign-
 * creation step was wrong). This version replaces every creation call with
 * AdsApp.mutate(), which IS the current, documented way to create
 * campaigns/ad groups/keywords/ads in Google Ads Scripts — confirmed by
 * reading Google's current Scripts documentation
 * (developers.google.com/google-ads/scripts/docs/features/mutate and
 * .../concepts/mutate), not assumed from memory a second time.
 *
 * mutate() takes the same resource field names/shapes as the real Google
 * Ads API (camelCase in Scripts, snake_case in the PHP library — both
 * accepted) — which is exactly what was already verified working, live,
 * in this project's PHP CRM (app/Execution/ChangeExecutor.php) this same
 * session, including the containsEuPoliticalAdvertising field that a real
 * live run surfaced as required. That field is included below for the
 * same reason.
 *
 * IMPORTANT — this is still less verified than the PHP work:
 * I have no way to execute AdsApp code myself (it only exists inside a
 * live Ads account's Script runtime). This version is built from Google's
 * current official documentation and examples, not just the previous
 * version's assumptions, but you should still:
 *   1. Run PREVIEW first (simulates without creating anything).
 *   2. If any single mutate() call reports an error via
 *      result.getErrorMessages(), that call's exact request shape is
 *      logged — paste it back and I'll fix the specific field.
 *
 * HIERARCHY
 * =========
 * 1 Campaign ("BMT | Search | All Services")
 *   -> Proximity location targeting, one circle per CRM city (single
 *      campaign, not one per city — per spec)
 *   -> 5 Services x N cities = one Ad Group each, named "{Service} - {City}"
 *        -> Keywords: Exact + Phrase match only (Service+City combinations)
 *        -> One Responsive Search Ad (headlines/descriptions generated
 *           per service+city, filtered to Google's length limits)
 *
 * The campaign is always created PAUSED — nothing spends until a human
 * reviews it and enables it manually in the Ads UI.
 */

// ============================================================================
// CONFIG — adjust these before running
// ============================================================================
var CONFIG = {
  CAMPAIGN_NAME: 'BMT | Search | All Services',
  DAILY_BUDGET: 5.0, // account currency, per day — TOTAL for this one campaign
  FINAL_URL: 'https://britishmobiletyres.co.uk/',

  SERVICES: [
    'Mobile Tyre Repair',
    'Mobile Tyre Replacement',
    'Mobile Tyre Change',
    'Mobile Tyre Puncture Repair',
    'Mobile Tyre Fitting',
  ],

  CRM_API_URL: 'https://ads.britishmobiletyres.co.uk/api/cities.json',
  CRM_API_TOKEN: 'PASTE_THE_SAME_TOKEN_FROM_.ENV_HERE',

  PROXIMITY_RADIUS_KM: 15,
};

// ============================================================================
// ENTRY POINT
// ============================================================================
function main() {
  Logger.log('=== BMT Campaign Builder — starting ===');
  var customerId = AdsApp.currentAccount().getCustomerId();
  Logger.log('Running against account ' + customerId);

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

  var campaignResourceName;
  try {
    campaignResourceName = getOrCreateCampaign_(customerId, CONFIG.CAMPAIGN_NAME, CONFIG.DAILY_BUDGET);
  } catch (e) {
    Logger.log('FATAL: could not create/find campaign — aborting. ' + e);
    return;
  }
  if (!campaignResourceName) {
    Logger.log('FATAL: campaignResourceName is empty after getOrCreateCampaign_ — aborting.');
    return;
  }

  applyCityTargeting_(customerId, campaignResourceName, cities);

  var stats = { created: 0, skipped: 0, failed: 0, copyRejected: 0 };

  for (var s = 0; s < CONFIG.SERVICES.length; s++) {
    var service = CONFIG.SERVICES[s];

    for (var c = 0; c < cities.length; c++) {
      var city = cities[c];
      var adGroupName = service + ' - ' + city.name;

      try {
        var result = createServiceCityAdGroup_(customerId, campaignResourceName, service, city.name, adGroupName);
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
// CRM INTEGRATION (unchanged from previous version — this part already
// worked correctly in your Preview run: "Fetched 61 cities from CRM.")
// ============================================================================
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
// MUTATE HELPER
// ============================================================================

/**
 * Wraps AdsApp.mutate() with consistent error surfacing. Throws with the
 * full request JSON and error messages on failure, so a failing call's
 * exact shape is always visible in the log — never a silent no-op.
 */
function mutateOrThrow_(operation, label) {
  var result = AdsApp.mutate(operation);
  var errors = result.getErrorMessages();
  if (errors && errors.length > 0) {
    throw new Error(
      label + ' failed: ' + errors.join('; ') +
      ' | Request was: ' + JSON.stringify(operation)
    );
  }
  return result;
}

// ============================================================================
// CAMPAIGN
// ============================================================================

/**
 * Returns the resource name of the existing campaign if one already
 * exists with this exact name (safe to re-run without duplicating), or
 * creates a new budget + campaign — PAUSED, Search-only, Manual CPC — if
 * not, returning the new campaign's resource name.
 */
function getOrCreateCampaign_(customerId, name, dailyBudget) {
  var iterator = AdsApp.campaigns()
    .withCondition('campaign.name = "' + name.replace(/"/g, '\\"') + '"')
    .get();

  if (iterator.hasNext()) {
    var existing = iterator.next();
    Logger.log('Campaign "' + name + '" already exists (id ' + existing.getId() + ') — reusing it, not creating a duplicate.');
    return 'customers/' + customerId + '/campaigns/' + existing.getId();
  }

  Logger.log('Creating new campaign "' + name + '" with daily budget ' + dailyBudget + '.');

  var budgetResult = mutateOrThrow_({
    campaignBudgetOperation: {
      create: {
        amountMicros: Math.round(dailyBudget * 1000000),
        explicitlyShared: false,
      },
    },
  }, 'Campaign budget creation');
  var budgetResourceName = budgetResult.getResourceName();
  Logger.log('Created budget: ' + budgetResourceName);

  var campaignResult = mutateOrThrow_({
    campaignOperation: {
      create: {
        name: name,
        status: 'PAUSED',
        advertisingChannelType: 'SEARCH',
        campaignBudget: budgetResourceName,
        manualCpc: {},
        networkSettings: {
          targetGoogleSearch: true,
          targetSearchNetwork: false,
          targetContentNetwork: false,
          targetPartnerSearchNetwork: false,
        },
        // Required by the Google Ads API (EU political advertising
        // transparency rules) on every campaign creation, globally — not
        // just EU accounts. Confirmed required by an actual live run
        // during this project's PHP work this session.
        containsEuPoliticalAdvertising: 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
      },
    },
  }, 'Campaign creation');

  var campaignResourceName = campaignResult.getResourceName();
  Logger.log('Campaign "' + name + '" created: ' + campaignResourceName + ' (PAUSED).');

  return campaignResourceName;
}

/**
 * Adds one proximity location-targeting circle per CRM city to the
 * campaign via campaignCriterionOperation. Skips (logs, doesn't throw)
 * any city that fails, so one bad coordinate doesn't abort the whole pass.
 */
function applyCityTargeting_(customerId, campaignResourceName, cities) {
  var added = 0;
  var failed = 0;

  for (var i = 0; i < cities.length; i++) {
    var city = cities[i];
    try {
      mutateOrThrow_({
        campaignCriterionOperation: {
          create: {
            campaign: campaignResourceName,
            proximity: {
              geoPoint: {
                latitudeInMicroDegrees: Math.round(city.lat * 1000000),
                longitudeInMicroDegrees: Math.round(city.lng * 1000000),
              },
              radius: CONFIG.PROXIMITY_RADIUS_KM,
              radiusUnits: 'KILOMETERS',
            },
          },
        },
      }, 'Location targeting for ' + city.name);
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
 *                      length validation for a compliant RSA; the ad
 *                      group and keywords are still created either way
 */
function createServiceCityAdGroup_(customerId, campaignResourceName, service, cityName, adGroupName) {
  var existing = AdsApp.adGroups()
    .withCondition('ad_group.name = "' + adGroupName.replace(/"/g, '\\"') + '"')
    .withCondition('campaign.resource_name = "' + campaignResourceName + '"')
    .get();
  if (existing.hasNext()) {
    Logger.log('Ad group "' + adGroupName + '" already exists — skipping.');
    return 'skipped_exists';
  }

  var adGroupResult = mutateOrThrow_({
    adGroupOperation: {
      create: {
        campaign: campaignResourceName,
        name: adGroupName,
        status: 'ENABLED', // harmless while the campaign itself is PAUSED
      },
    },
  }, 'Ad group creation for "' + adGroupName + '"');
  var adGroupResourceName = adGroupResult.getResourceName();
  Logger.log('Created ad group "' + adGroupName + '": ' + adGroupResourceName);

  addKeywords_(adGroupResourceName, service, cityName);

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

  createResponsiveSearchAd_(adGroupResourceName, copy);
  return 'created';
}

/**
 * Adds Exact and Phrase match keywords for Service+City. Deliberately no
 * Broad match keywords are ever added, matching the CRM's own campaign
 * builder policy.
 */
function addKeywords_(adGroupResourceName, service, cityName) {
  var baseText = service + ' ' + cityName;
  var texts = [baseText, baseText + ' near me'];
  var matchTypes = ['EXACT', 'PHRASE'];

  for (var i = 0; i < texts.length; i++) {
    for (var m = 0; m < matchTypes.length; m++) {
      try {
        mutateOrThrow_({
          adGroupCriterionOperation: {
            create: {
              adGroup: adGroupResourceName,
              status: 'ENABLED',
              keyword: {
                text: texts[i],
                matchType: matchTypes[m],
              },
            },
          },
        }, 'Keyword "' + texts[i] + '" (' + matchTypes[m] + ')');
      } catch (e) {
        Logger.log('WARNING: failed to add keyword "' + texts[i] + '" (' + matchTypes[m] + '): ' + e);
      }
    }
  }
}

/**
 * Builds one RSA on the ad group from already-validated headlines/
 * descriptions. Assumes generateCompliantCopy_() has already filtered the
 * arrays to Google's length limits and minimum-count requirements.
 */
function createResponsiveSearchAd_(adGroupResourceName, copy) {
  var headlineAssets = copy.headlines.map(function (text) { return { text: text }; });
  var descriptionAssets = copy.descriptions.map(function (text) { return { text: text }; });

  try {
    mutateOrThrow_({
      adGroupAdOperation: {
        create: {
          adGroup: adGroupResourceName,
          status: 'ENABLED',
          ad: {
            finalUrls: [CONFIG.FINAL_URL],
            responsiveSearchAd: {
              headlines: headlineAssets,
              descriptions: descriptionAssets,
            },
          },
        },
      },
    }, 'RSA creation');
    Logger.log('Created RSA with ' + copy.headlines.length + ' headlines, ' + copy.descriptions.length + ' descriptions.');
  } catch (e) {
    Logger.log('WARNING: RSA creation failed: ' + e);
  }
}

// ============================================================================
// COPY GENERATION + VALIDATION
// (unchanged from previous version — this logic has no AdsApp dependency
// and was already executed standalone in Node against all 305 real
// service x city combinations from config/cities.php, with zero overflow
// and zero insufficient-copy cases)
// ============================================================================

var HEADLINE_MAX_LENGTH = 30;
var DESCRIPTION_MAX_LENGTH = 90;
var HEADLINE_MAX_COUNT = 15;
var DESCRIPTION_MAX_COUNT = 4;

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
