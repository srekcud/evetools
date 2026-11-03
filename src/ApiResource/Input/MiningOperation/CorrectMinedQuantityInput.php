<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class CorrectMinedQuantityInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $typeId = null;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $quantity = null;
}
