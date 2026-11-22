<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectLineBlueprintCandidateResource;
use App\ApiResource\Industry\Project\ProjectLineBlueprintResource;
use App\Service\Industry\Project\Blueprint\LineBlueprintReading;
use App\Service\Industry\Project\PilotName;

final readonly class ProjectLineBlueprintResourceMapper
{
    private const string KIND_COPY = 'bpc';
    private const string KIND_ORIGINAL = 'bpo';

    public function toResource(LineBlueprintReading $reading): ProjectLineBlueprintResource
    {
        $attachment = $reading->attachment ?? throw new \LogicException('A step blueprint reading carries its attachment.');

        $resource = new ProjectLineBlueprintResource();
        $resource->id = $attachment->getId()->toRfc4122();
        $resource->bomLineId = $reading->line->getId()->toRfc4122();
        $resource->itemId = $reading->itemId;
        $resource->typeId = $reading->blueprintTypeId;
        $resource->typeName = $reading->blueprintTypeName;
        $resource->kind = self::kindOf($reading);
        $resource->runs = self::runsOf($reading);
        $resource->materialEfficiency = $reading->blueprint?->getMaterialEfficiency();
        $resource->timeEfficiency = $reading->blueprint?->getTimeEfficiency();
        $resource->attachedBy = PilotName::of($attachment->getAttachedBy());
        $resource->attachedAt = $attachment->getAttachedAt()->format(\DATE_ATOM);
        $resource->stepPlace = self::stepPlaceOf($reading);
        $resource->blueprintPlace = self::blueprintPlaceOf($reading);
        $resource->state = $reading->position->state;
        $resource->unknownReason = $reading->position->unknownReason;
        $resource->transferToMake = $reading->transferToMake;

        return $resource;
    }

    /**
     * @param list<LineBlueprintReading> $readings
     *
     * @return list<ProjectLineBlueprintResource>
     */
    public function toResources(array $readings): array
    {
        return array_map($this->toResource(...), $readings);
    }

    /**
     * @param list<LineBlueprintReading> $readings
     *
     * @return list<ProjectLineBlueprintCandidateResource>
     */
    public function toCandidates(array $readings): array
    {
        return array_map(static function (LineBlueprintReading $reading): ProjectLineBlueprintCandidateResource {
            $resource = new ProjectLineBlueprintCandidateResource();
            $resource->bomLineId = $reading->line->getId()->toRfc4122();
            $resource->itemId = $reading->itemId;
            $resource->typeId = $reading->blueprintTypeId;
            $resource->typeName = $reading->blueprintTypeName;
            $resource->kind = self::kindOf($reading);
            $resource->runs = self::runsOf($reading);
            $resource->materialEfficiency = $reading->blueprint?->getMaterialEfficiency();
            $resource->timeEfficiency = $reading->blueprint?->getTimeEfficiency();
            $resource->state = $reading->position->state;
            $resource->unknownReason = $reading->position->unknownReason;
            $resource->transferToMake = $reading->transferToMake;

            return $resource;
        }, $readings);
    }

    private static function kindOf(LineBlueprintReading $reading): ?string
    {
        if (null === $reading->blueprint) {
            return null;
        }

        return $reading->blueprint->isCopy() ? self::KIND_COPY : self::KIND_ORIGINAL;
    }

    private static function runsOf(LineBlueprintReading $reading): ?int
    {
        return null !== $reading->blueprint && $reading->blueprint->isCopy() ? $reading->blueprint->getRuns() : null;
    }

    /**
     * @return array{kind: string, locationId: ?int, name: ?string, solarSystemId: ?int, solarSystemName: ?string, unknownReason: ?string}
     */
    private static function stepPlaceOf(LineBlueprintReading $reading): array
    {
        return $reading->position->stepPlace->toArray();
    }

    /**
     * @return array{locationId: ?int, name: ?string, solarSystemName: ?string}
     */
    private static function blueprintPlaceOf(LineBlueprintReading $reading): array
    {
        $station = $reading->position->blueprintStation;

        return ['locationId' => $station?->locationId, 'name' => $station?->name, 'solarSystemName' => $station?->solarSystemName];
    }
}
