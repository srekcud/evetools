<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Industry\ProductSearchProvider;
use App\State\Provider\Industry\BlacklistSearchProvider;

#[ApiResource(
    shortName: 'ProductSearch',
    description: 'Search for manufacturable products',
    operations: [
        new Get(
            uriTemplate: '/industry/search',
            provider: ProductSearchProvider::class,
            output: ProductSearchListResource::class,
            openapiContext: [
                'summary' => 'Search products',
                'description' => 'Search for manufacturable products by name',
                'parameters' => [
                    [
                        'name' => 'q',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'description' => 'Search query (min 2 characters)',
                    ],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/industry/blacklist/search',
            provider: BlacklistSearchProvider::class,
            output: ProductSearchListResource::class,
            openapiContext: [
                'summary' => 'Search blacklist items',
                'description' => 'Search for items to add to blacklist',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProductSearchResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $typeName;
}
