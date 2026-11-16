<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;

class UnclaimedStockGuard
{
    public function __construct(
        private readonly ProjectContainerFulfillment $containerFulfillment,
        private readonly IndustryProjectContributionRepository $contributions,
    ) {
    }

    public function assertWithin(IndustryProjectBomLine $line, int $quantity, int $ownQuantity = 0): void
    {
        $project = $line->getProject();
        if (!\in_array($project->getStatus(), [ProjectStatus::InProgress, ProjectStatus::Selling], true)) {
            throw new \DomainException('The unclaimed stock can only be drawn once the project is in progress.');
        }

        $containerQuantity = $this->containerFulfillment->of($project)->containerQuantityOf($line);
        if (null === $containerQuantity) {
            throw new \DomainException('The container stock of this project is unknown: the unclaimed stock cannot be drawn.');
        }

        $claimedQuantity = $this->contributions->sumClaimedMaterialQuantityByLine($project)[$line->getId()->toRfc4122()] ?? 0;
        $unclaimedQuantity = UnclaimedStock::quantityOf($containerQuantity, $line->getRequiredQuantity(), $claimedQuantity)
            ?? throw new \LogicException('The unclaimed quantity of a known container stock is known.');

        if ($quantity > $unclaimedQuantity + $ownQuantity) {
            throw new \DomainException(\sprintf('The quantity exceeds the unclaimed stock of the line (%d).', $unclaimedQuantity + $ownQuantity));
        }
    }
}
