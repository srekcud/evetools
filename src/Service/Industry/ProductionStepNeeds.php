<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;

final readonly class ProductionStepNeeds
{
    /**
     * @param list<IndustryProjectStep> $productionSteps
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct products whose recipe exists
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @param array<string, IndustryActivityProduct> $productsByKey
     * @param array<int, string> $typeNames
     * @param array<int, array{typeId: int, reason: string}> $failedProducts
     * @param array<int, StepProductNeed> $needsByProduct consumers before materials
     * @param array<int, array{materialBonus: array{total: float, base: float, rig: float}, name: string|null}> $structureByStep
     */
    public function __construct(
        public array $productionSteps,
        public array $stepsByProduct,
        public array $materialsByKey,
        public array $productsByKey,
        public array $typeNames,
        public array $failedProducts,
        public array $needsByProduct,
        private array $structureByStep,
    ) {
    }

    /**
     * @return array{total: float, base: float, rig: float}
     */
    public function materialBonusOf(IndustryProjectStep $step): array
    {
        return $this->structureByStep[spl_object_id($step)]['materialBonus'];
    }

    public function structureNameOf(IndustryProjectStep $step): ?string
    {
        return $this->structureByStep[spl_object_id($step)]['name'];
    }
}
