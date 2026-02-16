<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\ProfitTracker\ProfitItemListProvider;

#[ApiResource(
    shortName: 'ProfitItemList',
    description: 'Per-item profit list',
    operations: [
        new Get(
            uriTemplate: '/profit-tracker/items',
            provider: ProfitItemListProvider::class,
            openapi: new Model\Operation(summary: 'Get per-item profits'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitItemListResource
{
    /** @var list<ProfitItemResource> */
    public array $items = [];
}
