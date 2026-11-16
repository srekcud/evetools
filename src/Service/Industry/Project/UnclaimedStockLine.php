<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class UnclaimedStockLine
{
    public function __construct(
        public string $bomLineId,
        public int $typeId,
        public string $typeName,
        public int $quantity,
    ) {
    }
}
