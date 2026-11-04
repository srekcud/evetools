<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningSite;

class CreateMiningSiteInput
{
    public string $label = '';

    public ?int $solarSystemId = null;
}
