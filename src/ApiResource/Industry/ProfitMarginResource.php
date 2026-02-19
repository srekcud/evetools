<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\ProfitMarginProvider;

#[ApiResource(
    shortName: 'IndustryProfitMargin',
    description: 'Profit margin analysis for manufacturing/reaction items',
    operations: [
        new Get(
            uriTemplate: '/industry/profit-margin/{typeId}',
            provider: ProfitMarginProvider::class,
            openapi: new Model\Operation(
                summary: 'Analyze profit margin',
                description: 'Computes production cost vs sell price for a manufacturable item',
                tags: ['Industry - Profit'],
                parameters: [
                    new Model\Parameter(name: 'typeId', in: 'path', required: true, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'runs', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 1]),
                    new Model\Parameter(name: 'me', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 10]),
                    new Model\Parameter(name: 'te', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 20]),
                    new Model\Parameter(name: 'solarSystemId', in: 'query', required: false, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'structureId', in: 'query', required: false, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'decryptorTypeId', in: 'query', required: false, schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitMarginResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $typeName;

    public bool $isT2 = false;

    public int $runs = 1;

    public int $outputQuantity = 0;

    public int $outputPerRun = 1;

    public float $materialCost = 0;

    /** @var list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}> */
    public array $materials = [];

    public float $jobInstallCost = 0;

    /** @var list<array{productTypeId: int, productName: string, activityType: string, runs: int, installCost: float}> */
    public array $jobInstallSteps = [];

    public float $inventionCost = 0;

    public float $copyCost = 0;

    public float $totalCost = 0;

    public float $costPerUnit = 0;

    /** @var array<string, mixed>|null */
    public ?array $invention = null;

    /** @var array{jitaSell: float|null, structureSell: float|null, structureBuy: float|null, contractSell: float|null, contractCount: int, structureId: int, structureName: string} */
    public array $sellPrices = [
        'jitaSell' => null,
        'structureSell' => null,
        'structureBuy' => null,
        'contractSell' => null,
        'contractCount' => 0,
        'structureId' => 0,
        'structureName' => '',
    ];

    public float $brokerFeeRate = 0.036;

    public float $salesTaxRate = 0.036;

    /** @var array<string, array{revenue: float, fees: float, profit: float, margin: float}|null> */
    public array $margins = [];

    public float $dailyVolume = 0;
}
