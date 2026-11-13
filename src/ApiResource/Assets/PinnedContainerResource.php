<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Assets\PinContainerInput;
use App\State\Processor\Assets\PinContainerProcessor;
use App\State\Processor\Assets\UnpinContainerProcessor;
use App\State\Provider\Assets\PinnedContainerCollectionProvider;
use App\State\Provider\Assets\PinnedContainerItemProvider;

#[ApiResource(
    shortName: 'PinnedContainer',
    description: 'Corporation containers pinned by the user, and containers of active projects pinned automatically',
    operations: [
        new GetCollection(
            uriTemplate: '/me/pinned-containers',
            provider: PinnedContainerCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List pinned containers', tags: ['Inventory']),
        ),
        new Get(
            uriTemplate: '/me/pinned-containers/{id}',
            provider: PinnedContainerItemProvider::class,
            openapi: new Model\Operation(summary: 'Get a pinned container (used for IRI resolution)', tags: ['Inventory']),
        ),
        new Post(
            uriTemplate: '/me/pinned-containers',
            input: PinContainerInput::class,
            processor: PinContainerProcessor::class,
            openapi: new Model\Operation(summary: 'Pin a corporation container', tags: ['Inventory']),
        ),
        new Delete(
            uriTemplate: '/me/pinned-containers/{id}',
            provider: PinnedContainerItemProvider::class,
            processor: UnpinContainerProcessor::class,
            openapi: new Model\Operation(summary: 'Unpin a container; a container pinned by an active project answers 409', tags: ['Inventory']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class PinnedContainerResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?int $itemId = null;

    public string $name;

    public ?string $locationName = null;

    public ?string $solarSystemName = null;

    public ?string $divisionName = null;

    public ?int $itemCount = null;

    public bool $found = false;

    public ?string $projectId = null;

    public ?string $projectName = null;
}
