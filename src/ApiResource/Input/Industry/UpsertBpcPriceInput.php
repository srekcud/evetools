<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpsertBpcPriceInput
{
    #[Assert\Positive]
    public int $blueprintTypeId;

    #[Assert\PositiveOrZero]
    public float $pricePerRun;
}
