<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\PivotAdvisorProvider;

#[ApiResource(
    shortName: 'IndustryPivotAdvisor',
    description: 'Pivot Advisor - find alternative products using shared components',
    operations: [
        new Get(
            uriTemplate: '/industry/pivot-advisor/{typeId}',
            provider: PivotAdvisorProvider::class,
            openapi: new Model\Operation(
                summary: 'Analyze pivot options',
                description: 'Find alternative products sharing components with the source product',
                tags: ['Industry - Scanner'],
                parameters: [
                    new Model\Parameter(name: 'typeId', in: 'path', required: true, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'runs', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 1]),
                    new Model\Parameter(name: 'solarSystemId', in: 'query', required: false, schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PivotAdvisorResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    /** @var array{typeId: int, typeName: string|null, groupName: string, marginPercent: float|null, sellPrice: float|null, dailyVolume: float|null, keyComponents: list<array{typeId: int, typeName: string|null, inStock: int}>, unknownReason?: string|null, missingPriceTypeIds?: list<int>, priceSources?: array{materials: array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}, sale: array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}|null, assumptions: array{runs: int, materialEfficiency: int}}}|array{} */
    public array $sourceProduct = [];

    /** @var list<array<string, mixed>> */
    public array $matrix = [];

    /** @var list<array{typeId: int, typeName: string|null, groupName: string, marginPercent: float|null, profitPerUnit: float|null, dailyVolume: float|null, coveragePercent: float|null, missingComponents: list<array{typeId: int, typeName: string|null, quantity: int, cost: float|null}>, additionalCost: float|null, estimatedProfit: float|null, score: float|null, unknownReason: string|null, missingPriceTypeIds: list<int>, priceSources: array{materials: array{market: string, side: string, method: string, coveragePercent: ?float}, sale: array{market: string, side: string, method: string, coveragePercent: ?float}|null, assumptions: array{runs: int, materialEfficiency: int}}}> */
    public array $candidates = [];

    /** @var list<int> */
    public array $matrixProductIds = [];
}
