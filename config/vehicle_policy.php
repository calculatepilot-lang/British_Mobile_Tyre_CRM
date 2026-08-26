<?php

/**
 * British Mobile Tyres: strict service eligibility policy.
 * This policy is shared by CRM lead validation, campaign planning and optimisation.
 */
return [
    'allowed' => [
        'car',
        'van',
        'caravan',
        'bus',
        'truck',
        'trailer',
    ],
    'labels' => [
        'car' => 'Car',
        'van' => 'Van',
        'caravan' => 'Caravan',
        'bus' => 'Bus',
        'truck' => 'Truck',
        'trailer' => 'Trailer',
    ],
    'excluded' => [
        'motorcycle', 'motorbike', 'bike', 'scooter', 'moped',
        'bicycle', 'e-bike', 'ebike', 'rickshaw', 'tuk-tuk', 'three wheeler',
    ],
    'negative_keyword_candidates' => [
        'motorcycle tyres', 'motorbike tyres', 'bike tyres', 'scooter tyres',
        'moped tyres', 'bicycle tyres', 'e-bike tyres', 'motorcycle puncture repair',
        'motorbike puncture repair', 'scooter puncture repair',
    ],
    'automatic_negative_application' => false,
    'review_required' => true,
];
