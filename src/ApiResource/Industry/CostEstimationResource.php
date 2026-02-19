<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\CostEstimationProvider;

#[ApiResource(
    shortName: 'IndustryCostEstimation',
    description: 'Production cost estimation for an industry project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{id}/cost-estimation',
            provider: CostEstimationProvider::class,
            openapi: new Model\Operation(summary: 'Get cost estimation', description: 'Returns material costs, job install costs, and total production cost breakdown', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CostEstimationResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public float $materialCost = 0;

    public float $jobInstallCost = 0;

    public float $bpoCost = 0;

    public float $totalCost = 0;

    public float $perUnit = 0;

    /** @var array<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}> */
    public array $materials = [];

    /** @var array<array{stepId: string, productTypeId: int, productName: string, solarSystemId: int, systemName: string, costIndex: float, runs: int, installCost: float}> */
    public array $jobInstallSteps = [];
}
