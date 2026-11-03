<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class CorrectMinedQuantityInput
{
    public const int MAX_TYPE_ID = 2_147_483_647;
    public const int MAX_QUANTITY = 1_000_000_000;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAX_TYPE_ID)]
    public ?int $typeId = null;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(self::MAX_QUANTITY)]
    public ?int $quantity = null;
}
