<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class PlanningBlueprintNames
{
    public function __construct(
        private IndustryActivityProductRepository $activityProductRepository,
        private InvTypeRepository $typeRepository,
    ) {
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return array<string, string> line id => name of the blueprint or formula that builds the product, absent when unknown
     */
    public function byLineId(array $lines): array
    {
        $blueprintIdByLineId = [];
        foreach ($lines as $line) {
            $producer = $this->activityProductRepository->findBlueprintForProduct($line->typeId, $this->activityIdOf($line));
            if (null !== $producer) {
                $blueprintIdByLineId[$line->lineId] = $producer->getTypeId();
            }
        }

        if ([] === $blueprintIdByLineId) {
            return [];
        }

        $blueprintTypes = $this->typeRepository->findByTypeIds(array_values(array_unique($blueprintIdByLineId)));

        $names = [];
        foreach ($blueprintIdByLineId as $lineId => $blueprintTypeId) {
            if (isset($blueprintTypes[$blueprintTypeId])) {
                $names[$lineId] = $blueprintTypes[$blueprintTypeId]->getTypeName();
            }
        }

        return $names;
    }

    private function activityIdOf(PlanningLine $line): int
    {
        return 'reaction' === $line->activity ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
