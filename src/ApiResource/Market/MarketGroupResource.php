<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Market\MarketGroupChildrenProvider;
use App\State\Provider\Market\MarketGroupProvider;

#[ApiResource(
    shortName: 'MarketGroup',
    description: 'Market group hierarchy',
    operations: [
        new GetCollection(
            uriTemplate: '/market/groups',
            provider: MarketGroupProvider::class,
            openapi: new Model\Operation(summary: 'List root market groups'),
        ),
        new Get(
            uriTemplate: '/market/groups/{groupId}',
            provider: MarketGroupChildrenProvider::class,
            openapi: new Model\Operation(summary: 'Get children of a market group'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MarketGroupResource
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public string $name;

    public ?int $parentId = null;

    public bool $hasChildren = false;

    public bool $hasTypes = false;
}
