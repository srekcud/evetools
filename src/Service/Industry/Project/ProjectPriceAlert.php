<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectPriceAlert
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $quantity,
        public float $jitaUnitPrice,
        public float $breakEvenPerUnit,
    ) {
    }

    public function gapPerUnit(): float
    {
        return $this->breakEvenPerUnit - $this->jitaUnitPrice;
    }
}
