<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\AttachLineBlueprintInput;
use App\State\Processor\Industry\Project\AttachLineBlueprintProcessor;
use App\State\Processor\Industry\Project\DetachLineBlueprintProcessor;
use App\State\Provider\Industry\Project\ProjectLineBlueprintItemProvider;
use App\State\Provider\Industry\Project\ProjectLineBlueprintsProvider;

#[ApiResource(
    shortName: 'ProjectLineBlueprint',
    description: 'A blueprint attached to a job step of a project, with its position against the station of the step',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/line-blueprints',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectLineBlueprintsProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List the blueprints attached to the steps of a project',
                description: 'Returns every attached blueprint with its position (here, elsewhere or unknown with its reason) and the transfer to make when it is not in the station of its step',
                tags: ['Group Industry - Containers'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/line-blueprints',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            processor: AttachLineBlueprintProcessor::class,
            input: AttachLineBlueprintInput::class,
            openapi: new Model\Operation(
                summary: 'Attach a blueprint to a job step',
                description: 'Attaches a blueprint visible to the caller, producing the product of a manufacturing or reaction line, to that line',
                tags: ['Group Industry - Containers'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/line-blueprints/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: ProjectLineBlueprintItemProvider::class,
            processor: DetachLineBlueprintProcessor::class,
            openapi: new Model\Operation(
                summary: 'Detach a blueprint from a job step',
                description: 'Detaches the blueprint (attacher, project owner or admin); the blueprint in the game and the linked jobs are untouched',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectLineBlueprintResource
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

    public ?string $attachedBy = null;

    public string $attachedAt = '';

    /**
     * @var array{kind: string, locationId: ?int, name: ?string, solarSystemId: ?int, solarSystemName: ?string, unknownReason: ?string}
     */
    public array $stepPlace = ['kind' => '', 'locationId' => null, 'name' => null, 'solarSystemId' => null, 'solarSystemName' => null, 'unknownReason' => null];

    /**
     * @var array{locationId: ?int, name: ?string, solarSystemName: ?string}
     */
    public array $blueprintPlace = ['locationId' => null, 'name' => null, 'solarSystemName' => null];

    public string $state = '';

    public ?string $unknownReason = null;

    /**
     * @var array{typeId: int, typeName: string, itemId: int, quantity: int, from: array{locationId: int, name: string, solarSystemName: ?string}, to: array{locationId: int, name: ?string, solarSystemId: ?int, solarSystemName: ?string}}|null
     */
    public ?array $transferToMake = null;
}
