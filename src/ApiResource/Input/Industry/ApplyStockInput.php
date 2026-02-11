<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class ApplyStockInput
{
    /**
     * @var array<array{typeName: string, quantity: int}>
     */
    #[Assert\NotNull]
    public array $items = [];
}
