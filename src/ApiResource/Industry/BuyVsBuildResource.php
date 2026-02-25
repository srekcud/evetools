<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\BuyVsBuildProvider;

#[ApiResource(
    shortName: 'IndustryBuyVsBuild',
    description: 'Buy vs Build analysis for intermediate components',
    operations: [
        new Get(
            uriTemplate: '/industry/buy-vs-build/{typeId}',
            provider: BuyVsBuildProvider::class,
            openapi: new Model\Operation(
                summary: 'Analyze buy vs build',
                description: 'Determines whether intermediate components should be bought or built for a product',
                tags: ['Industry - Scanner'],
                parameters: [
                    new Model\Parameter(name: 'typeId', in: 'path', required: true, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'runs', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 1]),
                    new Model\Parameter(name: 'me', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 10]),
                    new Model\Parameter(name: 'solarSystemId', in: 'query', required: false, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'structureId', in: 'query', required: false, schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class BuyVsBuildResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $typeName = '';

    public bool $isT2 = false;

    public int $runs = 1;

    public float $totalProductionCost = 0;

    public ?float $sellPrice = null;

    public ?float $marginPercent = null;

    /** @var list<array{typeId: int, typeName: string, quantity: int, stage: string, buildCost: float, buildMaterials: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>, buildJobInstallCost: float, buyCostJita: float|null, buyCostStructure: float|null, verdict: string, savings: float, savingsPercent: float, meUsed: int, runs: int}> */
    public array $components = [];

    public float $buildAllCost = 0;

    public float $buyAllCost = 0;

    public float $optimalMixCost = 0;

    /** @var list<int> */
    public array $buildTypeIds = [];

    /** @var list<int> */
    public array $buyTypeIds = [];
}
