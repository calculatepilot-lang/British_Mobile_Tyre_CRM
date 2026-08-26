<?php

declare(strict_types=1);

namespace BMT\Campaigns;

use RuntimeException;

final class CampaignPlanValidator
{
    public function validate(array $plan): void
    {
        if (empty($plan['cities'])) {
            throw new RuntimeException('Campaign plan must contain approved cities.');
        }

        foreach ($plan['cities'] as $city) {
            if (empty($city['name']) || !isset($city['lat'], $city['lng'])) {
                throw new RuntimeException('Every city must have a name and planning coordinates.');
            }
        }

        foreach (($plan['vehicles'] ?? []) as $vehicle) {
            if (!in_array($vehicle, ['car','van','caravan','bus','truck','trailer'], true)) {
                throw new RuntimeException('Campaign plan contains an unsupported vehicle type.');
            }
        }

        foreach (($plan['target_points'] ?? []) as $point) {
            if (empty($point['approved'])) {
                throw new RuntimeException('Unapproved road or service-area targeting point detected.');
            }
        }
    }
}
