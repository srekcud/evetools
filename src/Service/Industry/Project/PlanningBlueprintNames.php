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
        $products = [];
        foreach ($lines as $line) {
            $products[$line->lineId] = ['typeId' => $line->typeId, 'activity' => $line->activity];
        }

        return $this->byKey($products);
    }

    /**
     * @param array<string, array{typeId: int, activity: 'manufacturing'|'reaction'}> $products
     *
     * @return array<string, string> key => name of the blueprint or formula that builds the product, absent when unknown
     */
    public function byKey(array $products): array
    {
        $blueprintIdByKey = [];
        foreach ($products as $key => $product) {
            $producer = $this->activityProductRepository->findBlueprintForProduct($product['typeId'], $this->activityIdOf($product['activity']));
            if (null !== $producer) {
                $blueprintIdByKey[$key] = $producer->getTypeId();
            }
        }

        if ([] === $blueprintIdByKey) {
            return [];
        }

        $blueprintTypes = $this->typeRepository->findByTypeIds(array_values(array_unique($blueprintIdByKey)));

        $names = [];
        foreach ($blueprintIdByKey as $key => $blueprintTypeId) {
            if (isset($blueprintTypes[$blueprintTypeId])) {
                $names[$key] = $blueprintTypes[$blueprintTypeId]->getTypeName();
            }
        }

        return $names;
    }

    private function activityIdOf(string $activity): int
    {
        return 'reaction' === $activity ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
