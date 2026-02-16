<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Market;

class CreateAlertInput
{
    public int $typeId;

    /** 'above' or 'below' */
    public string $direction;

    public float $threshold;

    /** 'jita_sell' or 'jita_buy' */
    public string $priceSource;
}
