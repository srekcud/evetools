<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\ProductionOpportunitiesProvider;

#[ApiResource(
    shortName: 'IndustryOpportunities',
    description: 'Profitable products ranked by what the player can produce and sell in a week',
    operations: [
        new Get(
            uriTemplate: '/industry/opportunities',
            provider: ProductionOpportunitiesProvider::class,
            openapi: new Model\Operation(
                summary: 'Rank what to produce',
                description: 'Ranks profitable products at Jita and at the preferred market structure by weekly capacity (market, capital, slots)',
                tags: ['Industry - Opportunities'],
                parameters: [
                    new Model\Parameter(name: 'market', in: 'query', required: false, schema: ['type' => 'string', 'enum' => ['all', 'jita', 'capital'], 'default' => 'all']),
                    new Model\Parameter(name: 'sort', in: 'query', required: false, schema: ['type' => 'string', 'enum' => ['week', 'hour'], 'default' => 'week']),
                    new Model\Parameter(name: 'capital', in: 'query', required: false, schema: ['type' => 'integer', 'minimum' => 1]),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
    normalizationContext: ['skip_null_values' => false],
)]
class ProductionOpportunitiesResource
{
    public ?int $capitalCap = null;

    public ?string $capitalStructureName = null;

    /** @var array{manufacturing: int, reaction: int}|null */
    public ?array $freeSlots = null;

    public float $sellShareOfDailyVolume = 0.0;

    public bool $salesFeesKnown = false;

    public int $unknownProductCount = 0;

    /** @var list<array<string, mixed>> */
    public array $rows = [];
}
