<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectSurplusResource;
use App\Entity\IndustryProject;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectSurplusBuilder;
use App\Service\Industry\Project\ProjectSurplusLine;

final readonly class ProjectSurplusResourceMapper
{
    public function __construct(
        private ProjectSurplusBuilder $surplusBuilder,
    ) {
    }

    public function toResource(IndustryProject $project): ProjectSurplusResource
    {
        $surplus = $this->surplusBuilder->build($project);

        $resource = new ProjectSurplusResource();
        $resource->id = $project->getId()->toRfc4122();
        $resource->totalValue = $surplus->totalValue;
        $resource->stored = $project->getSurplusStoredAt() !== null;
        $resource->storedAt = $project->getSurplusStoredAt()?->format(\DateTimeInterface::ATOM);
        $resource->storedBy = PilotName::of($project->getSurplusStoredBy());
        $resource->lines = array_map(
            static fn (ProjectSurplusLine $line): array => [
                'lineId' => $line->lineId,
                'typeId' => $line->typeId,
                'typeName' => $line->typeName,
                'requiredQuantity' => $line->requiredQuantity,
                'contributedQuantity' => $line->contributedQuantity,
                'surplusQuantity' => $line->surplusQuantity,
                'unitPrice' => $line->unitPrice,
                'surplusValue' => $line->surplusValue,
                'source' => $line->source->value,
            ],
            $surplus->lines,
        );

        return $resource;
    }
}
