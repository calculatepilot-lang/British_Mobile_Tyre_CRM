<?php

// Config for CampaignPlanner::queueServiceCampaignProposals(). Produces
// 5 campaigns (one per service) x 8 ad groups (one per vehicle type) = 40
// ad groups. Keywords per ad group cover Service+Vehicle, Service+Location,
// and Service+Vehicle+Location for every one of the 61 cities in
// config/cities.php — see CampaignPlanner::buildKeywords() for the exact
// combinations. All 61 cities are also targeted via proximity location
// criteria on every campaign (real geo-targeting), independent of the
// location text baked into keywords.

return [
    'services' => [
        'repair' => 'Mobile Tyre Repair',
        'replacement' => 'Mobile Tyre Replacement',
        'change' => 'Mobile Tyre Change',
        'puncture_repair' => 'Mobile Tyre Puncture Repair',
        'fitting' => 'Mobile Tyre Fitting',
    ],

    // Ad-copy/keyword vehicle terms — deliberately separate from
    // config/vehicle_policy.php's eligibility list, which governs lead
    // validation, not keyword copy. Includes both spellings requested
    // ("Caravan" and "Carvan").
    'vehicles' => [
        'Car', 'Van', 'Caravan', 'Carvan', 'Truck', 'Bus', 'Trailer', 'Lorry',
    ],

    'final_url_base' => 'https://britishmobiletyres.co.uk/',

    // {service} and {vehicle} placeholders. Filtered to Google's RSA limits
    // (headlines <= 30 chars, descriptions <= 90) at generation time — any
    // combination that overflows for a given service/vehicle pair is
    // skipped rather than truncated.
    'headline_templates' => [
        '{service}',
        '{vehicle} {service}',
        'We Come To You',
        '24/7 Mobile Fitting',
        'Book Online Today',
        'Same Day Service',
        'No Callout Fee',
        'Fully Mobile Fitters',
        'Fast, Reliable, Local',
    ],
    'description_templates' => [
        '{service} for {vehicle}s, at your location. Book online today.',
        'We come to you — fast, reliable {service}. No garage visit needed.',
        'Same day {service} across England. Fully trained mobile fitters.',
    ],
];
