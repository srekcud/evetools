<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateUserSettingsInput
{
    private const int MAXIMUM_SOLAR_SYSTEM_ID = 2_147_483_647;

    public ?int $favoriteManufacturingSystemId = null;

    public ?int $favoriteReactionSystemId = null;

    #[Assert\PositiveOrZero]
    public ?float $exportCostPerM3 = null;

    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAXIMUM_SOLAR_SYSTEM_ID)]
    public ?int $courierHubSolarSystemId = null;

    #[Assert\PositiveOrZero]
    public ?float $jumpBridgeCostPerM3 = null;
}
