<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\DeclarePersonalStockContainerInput;
use App\State\Processor\Industry\DeclarePersonalStockContainerProcessor;
use App\State\Processor\Industry\RemovePersonalStockContainerProcessor;
use App\State\Provider\Industry\PersonalStockContainerCollectionProvider;
use App\State\Provider\Industry\PersonalStockContainerItemProvider;

#[ApiResource(
    shortName: 'PersonalStockContainer',
    description: 'Containers of the corporation divisions the user declares as their own stock',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/settings/personal-stock-containers',
            provider: PersonalStockContainerCollectionProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(summary: 'List my personal stock containers with their reading', tags: ['Industry - Configuration']),
        ),
        new Get(
            uriTemplate: '/industry/settings/personal-stock-containers/{id}',
            provider: PersonalStockContainerItemProvider::class,
            openapi: new Model\Operation(summary: 'Get a personal stock container (used for IRI resolution)', tags: ['Industry - Configuration']),
        ),
        new Post(
            uriTemplate: '/industry/settings/personal-stock-containers',
            input: DeclarePersonalStockContainerInput::class,
            processor: DeclarePersonalStockContainerProcessor::class,
            openapi: new Model\Operation(summary: 'Declare a corporation division container as personal stock; answers 404 when not visible, 422 when not a container, 409 when declared by another user or named like a project container', tags: ['Industry - Configuration']),
        ),
        new Delete(
            uriTemplate: '/industry/settings/personal-stock-containers/{id}',
            provider: PersonalStockContainerItemProvider::class,
            processor: RemovePersonalStockContainerProcessor::class,
            openapi: new Model\Operation(summary: 'Stop counting a container as personal stock', tags: ['Industry - Configuration']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class PersonalStockContainerResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $itemId;

    public string $name;

    public ?string $divisionName = null;

    public ?int $stationId = null;

    public ?string $stationName = null;

    public string $status;

    public ?string $unknownReason = null;

    public ?int $itemCount = null;
}
