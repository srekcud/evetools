<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Market;

class UpdateAlertInput
{
    public ?float $threshold = null;

    public ?string $direction = null;
}
