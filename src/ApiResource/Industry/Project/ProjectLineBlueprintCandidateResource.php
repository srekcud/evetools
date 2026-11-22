<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectLineBlueprintCandidatesProvider;

#[ApiResource(
    shortName: 'ProjectLineBlueprintCandidate',
    description: 'A blueprint visible to the caller that could be attached to a job step of a project',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/line-blueprint-candidates',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectLineBlueprintCandidatesProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List the blueprints that could be attached to the steps of a project',
                description: 'Returns, for each manufacturing or reaction line, the blueprints of the caller that produce its product and are not attached yet, here before elsewhere, most runs first',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectLineBlueprintCandidateResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $bomLineId = '';

    public int $itemId = 0;

    public ?int $typeId = null;

    public ?string $typeName = null;

    public ?string $kind = null;

    public ?int $runs = null;

    public ?int $materialEfficiency = null;

    public ?int $timeEfficiency = null;

    public string $state = '';

    public ?string $unknownReason = null;

    /**
     * @var array{typeId: int, typeName: string, itemId: int, quantity: int, from: array{locationId: int, name: string, solarSystemName: ?string}, to: array{locationId: int, name: ?string, solarSystemId: ?int, solarSystemName: ?string}}|null
     */
    public ?array $transferToMake = null;
}
