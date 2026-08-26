<?php

/**
 * Road and service-area targeting policy.
 * Google Ads activation must use only supported geo/proximity targets resolved and approved at runtime.
 */
return [
    'mode' => 'review_required',
    'enabled' => true,
    'corridor_types' => [
        'motorway',
        'major_a_road',
        'city_approach',
        'junction_area',
        'service_area',
    ],
    'default_radius_km' => 5.0,
    'max_radius_km_without_approval' => 8.0,
    'allow_invented_pois' => false,
    'require_coordinate_review' => true,
    'require_city_membership' => true,
    'activation_requires_approval' => true,
];
