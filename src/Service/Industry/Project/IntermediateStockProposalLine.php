<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class IntermediateStockProposalLine
{
    public function __construct(
        public string $bomItemId,
        public int $typeId,
        public string $typeName,
        public int $demandQuantity,
        public int $remainingQuantity,
        public int $personalQuantity,
        public ?int $containerQuantity,
        public int $stockQuantity,
        public int $proposedQuantity,
    ) {
    }
}
