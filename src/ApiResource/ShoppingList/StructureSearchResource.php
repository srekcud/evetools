<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\ShoppingList\StructureSearchProvider;

#[ApiResource(
    shortName: 'ShoppingListStructureSearch',
    description: 'Search for structures to use for shopping list',
    operations: [
        new Get(
            uriTemplate: '/shopping-list/search-structures',
            provider: StructureSearchProvider::class,
            openapiContext: [
                'summary' => 'Search for structures by name',
                'parameters' => [
                    ['name' => 'q', 'in' => 'query', 'type' => 'string', 'description' => 'Search query (min 3 characters)'],
                ],
            ],
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
