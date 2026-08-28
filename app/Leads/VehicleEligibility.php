<?php

declare(strict_types=1);

namespace BMT\Leads;

final class VehicleEligibility
{
    private array $policy;

    public function __construct(?array $policy = null)
    {
        $this->policy = $policy ?? require dirname(__DIR__, 2) . '/config/vehicle_policy.php';
    }

    public function normalise(string $vehicleType): string
    {
        return strtolower(trim($vehicleType));
    }

    public function isAllowed(string $vehicleType): bool
    {
        return in_array($this->normalise($vehicleType), $this->policy['allowed'], true);
    }

    public function assertAllowed(string $vehicleType): void
    {
        if (!$this->isAllowed($vehicleType)) {
            throw new \InvalidArgumentException('Vehicle type is not serviced by British Mobile Tyres.');
        }
    }

    public function allowed(): array
    {
        return $this->policy['allowed'];
    }

    public function excluded(): array
    {
        return $this->policy['excluded'];
    }
}
