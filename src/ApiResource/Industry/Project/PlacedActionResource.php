<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\PlacedActionsProvider;

#[ApiResource(
    shortName: 'PlacedAction',
    description: 'An action of the connected pilot, with the place where it is done',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/place-actions',
            provider: PlacedActionsProvider::class,
            openapi: new Model\Operation(
                summary: 'List the actions of the pilot across all projects, docked place first',
                description: 'One entry per action (buy, launch, check a deposit, accept a member), each with its place and whether a character of the pilot is docked there',
                tags: ['Group Industry - Actions'],
            ),
        ),
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/place-actions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: PlacedActionsProvider::class,
            openapi: new Model\Operation(
                summary: 'List the actions of the pilot on one project, docked place first',
                description: 'Same source as the cross-project list, limited to one project',
                tags: ['Group Industry - Actions'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    paginationEnabled: false,
)]
class PlacedActionResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $projectId = '';

    public ?string $projectName = null;

    public string $code = '';

    public ?string $bomLineId = null;

    public ?int $typeId = null;

    public ?string $typeName = null;

    public ?int $quantity = null;

    /**
     * @var array{kind: string, locationId: ?int, name: ?string, solarSystemId: ?int, solarSystemName: ?string, unknownReason: ?string}
     */
    public array $place = ['kind' => 'anywhere', 'locationId' => null, 'name' => null, 'solarSystemId' => null, 'solarSystemName' => null, 'unknownReason' => null];

    public ?bool $here = null;

    /**
     * @var 'granted'|'unknown'|null
     */
    public ?string $access = null;
}
