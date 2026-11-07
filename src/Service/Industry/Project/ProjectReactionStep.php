<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectReactionStep
{
    /**
     * @param list<array{typeId: int, typeName: string|null, quantityPerRun: int, totalQuantity: int, isFuelBlock: bool}>|null $inputs
     */
    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $runs,
        public int $producedQuantity,
        public ?int $surplusQuantity,
        public ?int $neededQuantity,
        public ?int $outputPerRun,
        public ?int $baseSecondsPerRun,
        public ?int $baseTotalSeconds,
        public ?array $inputs,
    ) {
    }
}
