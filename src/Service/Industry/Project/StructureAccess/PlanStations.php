<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\IndustryProject;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\ActionPlaceResolver;

final readonly class PlanStations
{
    public function __construct(private ActionPlaceResolver $placeResolver)
    {
    }

    /**
     * @return list<ActionPlace> stations of the plan (kind station only), distinct by location, in plan order
     */
    public function of(IndustryProject $project): array
    {
        $stations = [];
        foreach ($project->getBomItems() as $line) {
            if (null === $line->getPlannedStructureId()) {
                continue;
            }
            $place = $this->placeResolver->ofStep($line, $project->getOwner());
            if (ActionPlace::KIND_STATION === $place->kind && null !== $place->locationId) {
                $stations[$place->locationId] ??= $place;
            }
        }

        return array_values($stations);
    }
}
