<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectSurplusLine
{
    public function __construct(
        public string $lineId,
        public int $typeId,
        public string $typeName,
        public int $requiredQuantity,
        public int $contributedQuantity,
        public int $surplusQuantity,
        public ?float $unitPrice,
        public ?float $surplusValue,
    ) {
    }
}
