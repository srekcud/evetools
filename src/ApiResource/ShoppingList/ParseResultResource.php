<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ShoppingList\ParseListInput;
use App\State\Processor\ShoppingList\ParseProcessor;

#[ApiResource(
    shortName: 'ShoppingListParseResult',
    description: 'Parse a shopping list and get prices',
    operations: [
        new Post(
            uriTemplate: '/shopping-list/parse',
            processor: ParseProcessor::class,
            input: ParseListInput::class,
            openapiContext: [
                'summary' => 'Parse a shopping list and get prices',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ParseResultResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'parse';

    /** @var ShoppingListItemResource[] */
    public array $items = [];

    /** @var string[] */
    public array $notFound = [];

    public array $totals = [];

    public float $transportCostPerM3 = 1200.0;

    public ?int $structureId = null;

    public ?string $structureName = null;

    public bool $structureAccessible = false;

    public bool $structureFromCache = false;

    public ?string $structureLastSync = null;

    public ?string $priceError = null;
}
