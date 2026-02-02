<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\Provider\Pve\TypeSearchProvider;

#[ApiResource(
    shortName: 'PveTypeSearch',
    description: 'Search for item types',
    operations: [
        new GetCollection(
            uriTemplate: '/pve/search-types',
            provider: TypeSearchProvider::class,
            openapiContext: [
                'summary' => 'Search for item types by name',
                'parameters' => [
                    ['name' => 'query', 'in' => 'query', 'type' => 'string', 'description' => 'Search query (min 2 characters)'],
                ],
            ],
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
