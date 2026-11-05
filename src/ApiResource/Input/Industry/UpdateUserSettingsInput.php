<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateUserSettingsInput
{
    public ?int $favoriteManufacturingSystemId = null;

    public ?int $favoriteReactionSystemId = null;

    #[Assert\PositiveOrZero]
    public ?float $exportCostPerM3 = null;
}
