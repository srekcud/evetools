<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Quantity;

final readonly class MaterialToSource
{
    public function __construct(public int $typeId, public Quantity $quantity, public ActivityKind $consumedBy)
    {
    }
}
