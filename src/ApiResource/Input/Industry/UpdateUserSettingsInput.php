<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

class UpdateUserSettingsInput
{
    public ?int $favoriteManufacturingSystemId = null;

    public ?int $favoriteReactionSystemId = null;

    public ?float $brokerFeeRate = null;

    public ?float $salesTaxRate = null;

    public ?float $exportCostPerM3 = null;
}
