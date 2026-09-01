# Programmatic Search structure (400 ad groups)

## What this is

`SearchStructurePlanner` builds a full Search campaign structure crossing:

- **10 regional campaigns** (`config/campaign_regions.php`) — the 61 cities in
  `config/cities.php` grouped into real UK regions (London & M25, South East,
  South West, East of England, East Midlands, West Midlands, North West,
  Yorkshire & Humber, North East, Wales & Scotland)
- **5 services** (`config/services.php`) — Mobile Tyre Repair, Replacement,
  Change, Puncture Repair, Fitting
- **8 vehicle-type ad groups** (`config/ad_group_vehicles.php`) — Car, Van,
  Caravan, Carvan (retained misspelling variant), Truck, Bus, Trailer, Lorry

10 x 5 x 8 = **400 ad groups**. `config/ad_group_vehicles.php` maps each of
the 8 keyword-facing vehicle labels back to one of the 6 canonical types in
`config/vehicle_policy.php` (`carvan` → `caravan`, `lorry` → `truck`), so lead
qualification and quality reporting are unaffected — they only ever see the 6
CRM vehicle types.

Each ad group gets a generated keyword set — exact match, phrase match,
near-me, location-intent (using the region's two largest cities), and
vehicle+service combination keywords — plus draft responsive search ad copy
(4 headlines, 2 descriptions, word-boundary truncated to Google's 30/90
character limits).

## Safety model — this is not a separate autonomous system

This module deliberately extends the CRM's existing approval-then-execute
pattern (`CampaignPlanner` / `ChangeExecutor`) rather than being a standalone
Google Ads Script that creates or enables campaigns on its own:

1. `SearchStructurePlanner::build()` / `queueStructureProposals()` **never**
   call a Google Ads mutate service — they only write one proposal per
   region (10 total) to `automation_changes` for review on `/changes`,
   exactly like every other proposal type in this codebase.
2. Only after a human approves a proposal does
   `ChangeExecutor::createSearchStructureSkeleton()` create it for real:
   budget, campaign (always **PAUSED**), one proximity location target per
   city in the region, all 40 ad groups, every generated keyword, and one
   responsive search ad per ad group.
3. **Negative keywords are never applied automatically**, for the same
   reason `createCampaignSkeleton()` doesn't: `config/vehicle_policy.php`
   sets `automatic_negative_application = false`. Each proposal's
   `recommended_negative_keywords` (the vehicle-policy negative list plus
   common irrelevant-traffic terms — job seekers, wholesale, used tyres,
   generic tyre-shop searches) is surfaced as a post-creation checklist item
   on `/changes` instead, exactly like the existing per-city checklist.
4. No campaign created by this module is ever switched to ENABLED by any
   code path. A human reviews the generated keywords/ad copy, adds
   negatives, and enables it manually in the Google Ads UI.

## Coexistence with the per-city `CampaignPlanner`

This does **not** replace `CampaignPlanner`'s one-campaign-per-city plan —
both run from `cron/daily_audit.php`. Running both against the same account
creates two parallel campaign structures covering overlapping cities.
Decide which structure to actually activate (one campaign per city, or the
10-region/400-ad-group structure) before enabling anything — activating
both means duplicate ad auctions for the same searches.

## Operational notes

- Each region proposal is idempotent: re-running the planner skips a region
  if a campaign with the matching name already exists or has an open
  proposal, so running it daily alongside the per-city planner is safe.
- `ChangeExecutor::createSearchStructureSkeleton()` issues several thousand
  keyword/ad mutate operations per region (chunked into batches of 1000 to
  stay under Google Ads API per-request limits). Run `/changes` → "Run
  approved changes" from the CLI/cron context or expect it to take a while —
  it is not built for a fast web-request round trip when many regions are
  approved at once.
- `config/cities.php` includes Cardiff, Glasgow and Edinburgh alongside the
  58 English cities. `campaign_regions.php` keeps them as their own region
  rather than silently dropping them — flag to the account owner if
  England-only targeting is actually required.

## What's still a human decision

- Reviewing generated keyword text and ad copy for accuracy/tone per ad
  group before enabling.
- Adding negative keywords from `recommended_negative_keywords`.
- Bid strategy and per-keyword bid adjustments — everything here uses the
  account's existing Manual CPC setting with no per-keyword bids set.
- Ongoing search-term mining, keyword expansion and pause decisions once
  live — that's the existing daily optimiser's (`OptimiserService` /
  `ClaudeDailyOptimiser`) job, not this one-time structure build.
