<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Repository\IndustryProjectContributionRepository;

class UnclaimedStockLines
{
    public function __construct(
        private readonly ProjectContainerFulfillment $containerFulfillment,
        private readonly IndustryProjectContributionRepository $contributions,
    ) {
    }

    /**
     * @return list<UnclaimedStockLine>
     */
    public function of(IndustryProject $project): array
    {
        $fulfillments = $this->containerFulfillment->of($project);
        $claimedQuantities = $this->contributions->sumClaimedMaterialQuantityByLine($project);

        $lines = [];
        foreach ($project->getBomItems() as $line) {
            $lineId = $line->getId()->toRfc4122();
            $quantity = UnclaimedStock::ofLine($project, $line, $fulfillments, $claimedQuantities[$lineId] ?? 0);
            if (null !== $quantity && $quantity > 0) {
                $lines[] = new UnclaimedStockLine($lineId, $line->getTypeId(), $line->getTypeName(), $quantity);
            }
        }

        return $lines;
    }
}
