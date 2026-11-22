<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\ProjectContainerFulfillment;

final readonly class SourceMaterialActions implements ProjectActionSource
{
    public function __construct(
        private ProjectContainerFulfillment $containerFulfillment,
        private ActionPlaceResolver $placeResolver,
    ) {
    }

    private const array SOURCING_STATUSES = [ProjectStatus::Published, ProjectStatus::InProgress];

    public function actionsOf(IndustryProject $project, User $user): array
    {
        if (!\in_array($project->getStatus(), self::SOURCING_STATUSES, true)) {
            return [];
        }

        $fulfillments = $this->containerFulfillment->of($project);
        $actions = [];
        foreach ($project->getBomItems() as $line) {
            $remaining = $fulfillments->remainingOf($line);
            if ($line->isJob() || $remaining <= 0) {
                continue;
            }

            $actions[] = new PlacedAction(
                $project->getId()->toRfc4122(),
                $project->getName(),
                PlacedActionCode::SourceMaterial,
                $line->getId()->toRfc4122(),
                $line->getTypeId(),
                $line->getTypeName(),
                $remaining,
                $this->placeResolver->ofMarket($line->getSourcingMarket(), $user),
            );
        }

        return $actions;
    }
}
