<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\ShoppingList\ShareListInput;
use App\State\Processor\ShoppingList\ShareListProcessor;
use App\State\Provider\ShoppingList\SharedShoppingListProvider;

#[ApiResource(
    shortName: 'SharedShoppingList',
    description: 'Shared shopping lists with expiration',
    operations: [
        new Post(
            uriTemplate: '/shopping-list/share',
            security: "is_granted('ROLE_USER')",
            processor: ShareListProcessor::class,
            input: ShareListInput::class,
            openapi: new Model\Operation(summary: 'Create a shareable link for a shopping list', description: 'Creates a shareable link that expires after 1 week'),
        ),
        new Get(
            uriTemplate: '/shopping-list/shared/{token}',
            provider: SharedShoppingListProvider::class,
            openapi: new Model\Operation(summary: 'Get a shared shopping list by token', description: 'Retrieves a shared shopping list. No authentication required.'),
        ),
    ],
)]
class SharedShoppingListResource
{
    #[ApiProperty(identifier: true)]
    public string $token;

    /** @var array<ShoppingListItemResource|array<string, mixed>> */
    public array $items = [];

    /** @var string[] */
    public array $notFound = [];

    public array $totals = [];

    public float $transportCostPerM3 = 1200.0;

    public ?int $structureId = null;

    public ?string $structureName = null;

    public string $createdAt;

    public string $expiresAt;

    public ?string $shareUrl = null;
}
