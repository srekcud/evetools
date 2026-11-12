<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectT2BlueprintCopyProposalResource;
use App\Service\Industry\Project\T2BlueprintCopyProposal;
use App\Service\Industry\Project\T2BlueprintCopyProposalLine;

final class ProjectT2BlueprintCopyProposalResourceMapper
{
    public static function toResource(string $projectId, T2BlueprintCopyProposal $proposal): ProjectT2BlueprintCopyProposalResource
    {
        $resource = new ProjectT2BlueprintCopyProposalResource();
        $resource->id = $projectId;
        $resource->state = $proposal->state;
        $resource->unknownReason = $proposal->unknownReason;
        $resource->lines = array_map(
            static fn (T2BlueprintCopyProposalLine $line): array => [
                'productTypeId' => $line->productTypeId,
                'typeName' => $line->typeName,
                'manufacturingRuns' => $line->manufacturingRuns,
                'personalRuns' => $line->personalRuns,
                'containerRuns' => $line->containerRuns,
                'availableRuns' => $line->availableRuns,
                'proposedRuns' => $line->proposedRuns,
                'declaredRuns' => $line->declaredRuns,
            ],
            $proposal->lines,
        );

        return $resource;
    }
}
