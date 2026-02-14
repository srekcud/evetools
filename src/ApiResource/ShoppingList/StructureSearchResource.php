<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\ShoppingList\StructureSearchProvider;

#[ApiResource(
    shortName: 'ShoppingListStructureSearch',
    description: 'Search for structures to use for shopping list',
    operations: [
        new Get(
            uriTemplate: '/shopping-list/search-structures',
            provider: StructureSearchProvider::class,
            openapi: new Model\Operation(
                summary: 'Search for structures by name',
                parameters: [
                    new Model\Parameter(name: 'q', in: 'query', schema: ['type' => 'string']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StructureSearchResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'search';

    /** @var StructureSearchResultResource[] */
    public array $results = [];
}
