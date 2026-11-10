<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectSellForecastProvider;

#[ApiResource(
    shortName: 'ProjectSellForecast',
    description: 'Net revenue forecast of what an industry project still has to sell',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/sell-forecast',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSellForecastProvider::class,
            openapi: new Model\Operation(
                summary: 'Get project sell forecast',
                description: 'Values the remaining quantity of each product at the lowest sell order of the project sell structure, with Jita beside it',
                tags: ['Group Industry - Sell forecast'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectSellForecastResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    /**
     * @var array{id: int, name: string}|null
     */
    public ?array $sellStructure = null;

    /**
     * @var list<array{
     *     typeId: int,
     *     typeName: string,
     *     quantity: int,
     *     structure: array{name: string, unitPrice: float|null, coveragePercent: float|null, feeRate: float|null, netUnitPrice: float|null, netRevenue: float|null, daysToSell: float|null}|null,
     *     jita: array{name: string, unitPrice: float|null, coveragePercent: float|null, feeRate: float|null, netUnitPrice: float|null, netRevenue: float|null, daysToSell: float|null},
     * }>
     */
    public array $products = [];

    /**
     * @var array{structureNetRevenue: float|null, jitaNetRevenue: float|null, structureUnknownProductCount: int, jitaUnknownProductCount: int, remainingCost: float|null, remainingCostUnknownLineCount: int, structureProfit: float|null, jitaProfit: float|null}
     */
    public array $totals;
}
