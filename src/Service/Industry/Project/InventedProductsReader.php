<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\InventionService;

class InventedProductsReader
{
    public function __construct(
        private readonly InventionService $inventionService,
    ) {
    }

    /**
     * @return array<int, array{typeName: string, manufacturingRuns: int}>
     */
    public function inventedProductsOf(IndustryProject $project): array
    {
        $products = [];
        foreach ($project->getBomItems() as $bomLine) {
            if (!$bomLine->isJob() || IndustryProjectBomLine::ACTIVITY_MANUFACTURING !== $bomLine->getActivityType()) {
                continue;
            }

            $typeId = $bomLine->getTypeId();
            $runs = $bomLine->getRuns() ?? throw new \LogicException(\sprintf('The manufacturing line of type %d has no runs.', $typeId));
            if (isset($products[$typeId])) {
                $products[$typeId]['manufacturingRuns'] += $runs;
            } else {
                $products[$typeId] = ['typeName' => $bomLine->getTypeName(), 'manufacturingRuns' => $runs];
            }
        }

        return array_filter($products, fn (int $typeId): bool => null !== $this->inventionService->getInventionData($typeId), ARRAY_FILTER_USE_KEY);
    }
}
