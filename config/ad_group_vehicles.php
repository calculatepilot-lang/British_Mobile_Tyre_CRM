<?php

/**
 * The 8 vehicle-type ad-group variants used by SearchStructurePlanner. This
 * is deliberately a superset of config/vehicle_policy.php's 6-value CRM lead
 * enum: 'carvan' is a retained real-world misspelling people actually search
 * for, and 'lorry' is a synonym searchers use. Both map back to an
 * eligibility_type from vehicle_policy.php so downstream lead-quality
 * reporting and qualification logic are unaffected — they only ever see the
 * 6 canonical types.
 */
return [
    ['slug' => 'car', 'label' => 'Car', 'query_term' => 'car', 'eligibility_type' => 'car'],
    ['slug' => 'van', 'label' => 'Van', 'query_term' => 'van', 'eligibility_type' => 'van'],
    ['slug' => 'caravan', 'label' => 'Caravan', 'query_term' => 'caravan', 'eligibility_type' => 'caravan'],
    ['slug' => 'carvan', 'label' => 'Carvan', 'query_term' => 'carvan', 'eligibility_type' => 'caravan'],
    ['slug' => 'truck', 'label' => 'Truck', 'query_term' => 'truck', 'eligibility_type' => 'truck'],
    ['slug' => 'bus', 'label' => 'Bus', 'query_term' => 'bus', 'eligibility_type' => 'bus'],
    ['slug' => 'trailer', 'label' => 'Trailer', 'query_term' => 'trailer', 'eligibility_type' => 'trailer'],
    ['slug' => 'lorry', 'label' => 'Lorry', 'query_term' => 'lorry', 'eligibility_type' => 'truck'],
];
