<?php

// Config for CampaignPlanner::queueServiceCampaignProposals(). Produces
// 5 campaigns (one per service) x 8 ad groups (one per vehicle type) = 40
// ad groups. All 61 cities from config/cities.php are targeted on every
// campaign via proximity location criteria (real geo-targeting) — the
// "city cluster" labels below are ONLY used to generate a few realistic
// local-intent keyword phrases per ad group; they are NOT a substitute for
// the actual per-city location targeting, and are not true geographic
// regions (just an even chunking of the city list, labelled by its first
// city, since config/cities.php has no region field to group by properly).
// Adjust cluster labels/groupings by hand if a chunk reads oddly.

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

    // Regenerate by chunking config/cities.php cities into 8 groups and
    // labelling each by its first city, e.g. "London area".
    'city_cluster_count' => 8,

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
