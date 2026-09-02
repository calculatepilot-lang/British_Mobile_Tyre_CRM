<?php

// Templates for the ad-group content proposer (CampaignPlanner::queueAdGroupContentProposals).
// {city} is replaced with the campaign's city name. Character limits follow
// Google Ads Responsive Search Ad limits: headlines <= 30 chars, descriptions
// <= 90 chars, checked at generation time — a template that would overflow
// for a given city name is skipped rather than truncated silently.
//
// final_url_base points at the site root. Update this (or add a per-city
// override under final_url_overrides) once real landing pages exist per
// city — a working final URL is required before a campaign can go live,
// and this default is a starting point, not a verified landing page.

return [
    'final_url_base' => 'https://britishmobiletyres.co.uk/',
    'final_url_overrides' => [
        // 'London' => 'https://britishmobiletyres.co.uk/london/',
    ],

    // Headlines: {city} placeholder allowed. Keep the non-{city} portion
    // short enough that every city name in config/cities.php still fits
    // within 30 characters once substituted.
    'headlines' => [
        'Mobile Tyre Fitting {city}',
        'We Come To You in {city}',
        '24/7 Mobile Tyre Service',
        'Same Day Tyre Fitting',
        'No Callout Fee',
        'Car, Van & Fleet Tyres',
        'Fully Mobile Tyre Fitters',
        'Book Online in Minutes',
        'Trusted Mobile Tyre Team',
        'Fast, Reliable, Local',
    ],

    // Descriptions: <= 90 characters each.
    'descriptions' => [
        'Professional mobile tyre fitting at home, work or roadside across {city}. Book online today.',
        'Car, van, caravan and fleet tyres fitted at your location in {city}. Fast, friendly service.',
        'No need to visit a garage — our mobile fitters come to you anywhere in {city}, 7 days a week.',
    ],

    // Broad themes used to generate keyword text per ad group. {city} is
    // substituted; keywords are proposed as Phrase match by default.
    'keyword_themes' => [
        'mobile tyre fitting {city}',
        'mobile tyres {city}',
        'tyre fitting {city}',
        'car tyres {city}',
        'van tyres {city}',
        'emergency tyre fitting {city}',
        'mobile tyre replacement {city}',
    ],
];
