<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\FactoryProjectStockCollectionProvider;

#[ApiResource(
    shortName: 'FactoryProjectStock',
    description: 'Material reserved, consumed or returned by the projects of the user',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/factory/project-stock',
            provider: FactoryProjectStockCollectionProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(summary: 'List the stock reserved, consumed or returned by my projects', tags: ['Industry - Factory']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class FactoryProjectStockResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $projectId;

    public ?string $projectName = null;

    public string $projectStatus;

    public int $typeId;

    public string $typeName;

    public string $state;

    public int $quantity;
}
