<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

class CorrectMinedQuantityInput
{
    public ?int $typeId = null;

    public ?int $quantity = null;
}
