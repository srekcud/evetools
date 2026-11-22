<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

final class DeclarePersonalStockContainerInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $itemId = null;
}
