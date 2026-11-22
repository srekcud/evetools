<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

final readonly class WorkTypeClassifier
{
    public function __construct(private ShipProducts $shipProducts)
    {
    }

    /**
     * @param list<int> $targetProductTypeIds
     */
    public function classify(int $productTypeId, ActivityKind $activity, array $targetProductTypeIds): WorkType
    {
        if (ActivityKind::Reaction === $activity) {
            return WorkType::Reaction;
        }
        if (\in_array($productTypeId, $targetProductTypeIds, true)) {
            return WorkType::FinalProducts;
        }

        return $this->shipProducts->isShip($productTypeId) ? WorkType::Hulls : WorkType::Components;
    }
}
