<?php

/**
 * Groups config/cities.php's 61 cities into 10 regional Search campaigns for
 * SearchStructurePlanner (10 campaigns x 5 services x 8 vehicles = 400 ad
 * groups). Grouping follows real UK regions so each campaign's proximity
 * targeting circles are geographically coherent rather than an arbitrary
 * chunk of the list. City names must match config/cities.php exactly —
 * SearchStructurePlanner resolves lat/lng/big from there at build time.
 *
 * Note: config/cities.php already includes Cardiff, Glasgow and Edinburgh
 * (not England) alongside the 58 English cities. They're kept here as their
 * own region rather than silently dropped, since removing live-configured
 * cities is a business decision, not a code one — flag to the account owner
 * if England-only targeting is actually required.
 */
return [
    [
        'key' => 'london_m25',
        'name' => 'London & M25',
        'cities' => ['London', 'Luton', 'Slough', 'Watford', 'Basildon', 'Crawley', 'Guildford'],
    ],
    [
        'key' => 'south_east',
        'name' => 'South East',
        'cities' => ['Southampton', 'Portsmouth', 'Brighton', 'Reading', 'Milton Keynes', 'Oxford', 'Maidstone'],
    ],
    [
        'key' => 'south_west',
        'name' => 'South West',
        'cities' => ['Bristol', 'Bournemouth', 'Swindon', 'Plymouth', 'Exeter', 'Gloucester', 'Cheltenham'],
    ],
    [
        'key' => 'east_of_england',
        'name' => 'East of England',
        'cities' => ['Norwich', 'Cambridge', 'Chelmsford', 'Ipswich', 'Peterborough', 'Colchester', 'Southend-on-Sea'],
    ],
    [
        'key' => 'east_midlands',
        'name' => 'East Midlands',
        'cities' => ['Nottingham', 'Leicester', 'Northampton', 'Derby'],
    ],
    [
        'key' => 'west_midlands',
        'name' => 'West Midlands',
        'cities' => ['Birmingham', 'Coventry', 'Stoke-on-Trent', 'Wolverhampton', 'Worcester'],
    ],
    [
        'key' => 'north_west',
        'name' => 'North West',
        'cities' => ['Manchester', 'Liverpool', 'Preston', 'Bolton', 'Blackpool', 'Warrington', 'Oldham', 'Blackburn'],
    ],
    [
        'key' => 'yorkshire_humber',
        'name' => 'Yorkshire & Humber',
        'cities' => ['Leeds', 'Sheffield', 'Bradford', 'Hull', 'York', 'Rotherham', 'Wakefield', 'Huddersfield', 'Doncaster'],
    ],
    [
        'key' => 'north_east',
        'name' => 'North East',
        'cities' => ['Newcastle', 'Sunderland', 'Middlesbrough', 'Gateshead'],
    ],
    [
        'key' => 'wales_scotland',
        'name' => 'Wales & Scotland',
        'cities' => ['Cardiff', 'Glasgow', 'Edinburgh'],
    ],
];
