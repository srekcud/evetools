<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Pve\TypeSearchProvider;

#[ApiResource(
    shortName: 'PveTypeSearch',
    description: 'Search for item types',
    operations: [
        new GetCollection(
            uriTemplate: '/pve/search-types',
            provider: TypeSearchProvider::class,
            openapi: new Model\Operation(
                summary: 'Search for item types by name',
                tags: ['Revenue - PVE'],
                parameters: [
                    new Model\Parameter(name: 'query', in: 'query', schema: ['type' => 'string']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class TypeSearchResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId = 0;

    public string $typeName = '';
}
