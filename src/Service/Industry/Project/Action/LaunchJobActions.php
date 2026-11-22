<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;

final readonly class LaunchJobActions implements ProjectActionSource
{
    public function __construct(private ActionPlaceResolver $placeResolver)
    {
    }

    public function actionsOf(IndustryProject $project, User $user): array
    {
        if (ProjectStatus::InProgress !== $project->getStatus()) {
            return [];
        }

        $actions = [];
        foreach ($project->getBomItems() as $line) {
            $runs = $line->getRuns();
            if (!$line->isJob() || (null !== $runs && ($runs <= $line->getCountedRuns() || $line->getDeliveredRuns() >= $runs))) {
                continue;
            }

            $actions[] = new PlacedAction(
                $project->getId()->toRfc4122(),
                $project->getName(),
                PlacedActionCode::LaunchJob,
                $line->getId()->toRfc4122(),
                $line->getTypeId(),
                $line->getTypeName(),
                null === $runs ? null : $runs - $line->getCountedRuns(),
                $this->placeResolver->ofStep($line, $project->getOwner()),
            );
        }

        return $actions;
    }
}
