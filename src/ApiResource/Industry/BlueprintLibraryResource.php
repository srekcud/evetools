<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Assets\SyncStatusResource;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\RefreshBlueprintsProcessor;
use App\State\Provider\Industry\BlueprintLibraryProvider;

#[ApiResource(
    shortName: 'BlueprintLibrary',
    description: 'Blueprints owned by the user and by the corporation of the main character',
    operations: [
        new Get(
            uriTemplate: '/industry/blueprints',
            provider: BlueprintLibraryProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the blueprint library',
                description: 'Returns the blueprints of every character of the user and the blueprints of the corporation of the main character that sit in a visible division',
                tags: ['Industry - Blueprints'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/blueprints/refresh',
            processor: RefreshBlueprintsProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            output: SyncStatusResource::class,
            openapi: new Model\Operation(
                summary: 'Refresh the blueprint library',
                description: 'Queues a sync of the blueprints of every character of the user and of their corporations',
                tags: ['Industry - Blueprints'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
    normalizationContext: ['skip_null_values' => false],
)]
class BlueprintLibraryResource
{
    public string $corporationState;

    /** @var list<array{typeId: int, name: string, kind: string, me: int, te: int, runs: int|null, scope: string, ownerName: string|null, locationId: int, divisionNumber: int|null, count: int, usedByRunningJob: bool}> */
    public array $blueprints = [];
}
