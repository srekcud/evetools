<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateUserSettingsInput
{
    public const float MAXIMUM_RATE = 1.0;

    public ?int $favoriteManufacturingSystemId = null;

    public ?int $favoriteReactionSystemId = null;

    #[Assert\Range(min: 0, max: self::MAXIMUM_RATE)]
    public ?float $brokerFeeRate = null;

    #[Assert\Range(min: 0, max: self::MAXIMUM_RATE)]
    public ?float $salesTaxRate = null;

    #[Assert\PositiveOrZero]
    public ?float $exportCostPerM3 = null;
}
