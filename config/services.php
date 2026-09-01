<?php

/**
 * The 5 services the Search structure builder (SearchStructurePlanner) crosses
 * with config/ad_group_vehicles.php to produce 5 x 8 = 40 ad groups per
 * campaign. query_terms are short phrase fragments combined with a vehicle
 * term to generate keyword text — kept to 2 variants per service so the
 * generated keyword set per ad group stays reviewable rather than exploding
 * combinatorially.
 */
return [
    [
        'slug' => 'mobile_tyre_repair',
        'label' => 'Mobile Tyre Repair',
        'query_terms' => ['mobile tyre repair', 'tyre repair'],
    ],
    [
        'slug' => 'mobile_tyre_replacement',
        'label' => 'Mobile Tyre Replacement',
        'query_terms' => ['mobile tyre replacement', 'tyre replacement'],
    ],
    [
        'slug' => 'mobile_tyre_change',
        'label' => 'Mobile Tyre Change',
        'query_terms' => ['mobile tyre change', 'tyre change'],
    ],
    [
        'slug' => 'mobile_tyre_puncture_repair',
        'label' => 'Mobile Tyre Puncture Repair',
        'query_terms' => ['mobile puncture repair', 'tyre puncture repair'],
    ],
    [
        'slug' => 'mobile_tyre_fitting',
        'label' => 'Mobile Tyre Fitting',
        'query_terms' => ['mobile tyre fitting', 'tyre fitting'],
    ],
];
