<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectSellOrdersProvider;

#[ApiResource(
    shortName: 'ProjectSellOrders',
    description: 'Sell orders of the connected pilot for the products of an industry project, compared to the best price',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/sell-orders',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSellOrdersProvider::class,
            openapi: new Model\Operation(
                summary: 'Get my sell orders of the project products',
                description: 'Returns the sell orders of the characters of the connected user, read-only, with their position against the best sell price',
                tags: ['Group Industry - Sell orders'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectSellOrdersResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    /**
     * @var list<array{
     *     orderId: int,
     *     characterName: string,
     *     typeId: int,
     *     typeName: string,
     *     volumeRemain: int,
     *     locationId: int,
     *     marketName: string|null,
     *     price: float,
     *     bestPrice: float|null,
     *     rank: int|null,
     *     state: string,
     *     unknownReason: string|null,
     *     undercutSince: string|null,
     *     suggestedPrice: float|null,
     * }>
     */
    public array $orders = [];

    /**
     * @var list<string>
     */
    public array $charactersToReconnect = [];

    public ?string $syncedAt = null;
}
