<?php

// Add only cities that British Mobile Tyres actually serves.
// No campaign creation code should activate a city unless enabled is true.
return [
    'mode' => 'review_required',
    'cities' => [
        // ['name' => 'Manchester', 'enabled' => false, 'campaign_group' => 'North West'],
        // ['name' => 'Liverpool', 'enabled' => false, 'campaign_group' => 'North West'],
        // ['name' => 'Birmingham', 'enabled' => false, 'campaign_group' => 'West Midlands'],
    ],
];
