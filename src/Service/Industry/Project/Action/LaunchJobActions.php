<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\StructureAccess\MemberStructureAccess;
use App\Service\Industry\Project\StructureAccess\StructureIds;

final readonly class LaunchJobActions implements ProjectActionSource
{
    public function __construct(
        private ActionPlaceResolver $placeResolver,
        private MemberStructureAccess $memberAccess,
    )
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

            $place = $this->placeResolver->ofStep($line, $project->getOwner());
            $access = $this->accessOf($place, $user);
            if (StructureAccessStatus::Denied === $access) {
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
                $place,
                $access,
            );
        }

        return $actions;
    }

    private function accessOf(ActionPlace $place, User $user): ?StructureAccessStatus
    {
        if (ActionPlace::KIND_STATION !== $place->kind || null === $place->locationId || !StructureIds::isStructure($place->locationId)) {
            return null;
        }

        return $this->memberAccess->of($user, $place->locationId);
    }
}
