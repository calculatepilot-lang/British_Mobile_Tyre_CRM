<?php

declare(strict_types=1);

namespace App\Campaigns;

final class CampaignPlanner
{
    private array $cities;
    private array $vehicles;
    private array $roads;

    public function __construct()
    {
        $this->cities = require dirname(__DIR__, 2) . '/config/cities.php';
        $this->vehicles = require dirname(__DIR__, 2) . '/config/vehicle_policy.php';
        $this->roads = require dirname(__DIR__, 2) . '/config/road_service_targeting.php';
    }

    /**
     * Produces a reviewable plan only. It never mutates Google Ads.
     */
    public function build(): array
    {
        $markets = [];
        foreach ($this->cities['cities'] as $city) {
            if (!$city['enabled']) {
                continue;
            }
            $markets[] = [
                'city' => $city['name'],
                'priority' => $city['big'] ? 'high' : 'standard',
                'planning_centre' => ['lat' => $city['lat'], 'lng' => $city['lng']],
                'vehicle_types' => $this->vehicles['allowed'],
                'road_strategy' => $this->roads['enabled'] ? $this->roads['corridor_types'] : [],
                'activation' => 'review_required',
            ];
        }

        return [
            'mode' => 'plan_only',
            'country_code' => $this->cities['country_code'],
            'location_presence_only' => $this->cities['location_presence_only'],
            'markets' => $markets,
            'excluded_vehicle_terms' => $this->vehicles['negative_keyword_candidates'],
            'notes' => [
                'No campaign, ad group, keyword, location target or negative keyword is created by this planner.',
                'Road and service-area points must be independently verified and approved before activation.',
            ],
        ];
    }
}
