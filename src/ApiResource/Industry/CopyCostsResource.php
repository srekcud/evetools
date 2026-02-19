<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\CopyCostsProvider;

#[ApiResource(
    shortName: 'IndustryCopyCosts',
    description: 'T1 BPC copy costs for an industry project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{id}/copy-costs',
            provider: CopyCostsProvider::class,
            openapi: new Model\Operation(summary: 'Get copy costs', description: 'Returns T1 BPC copy costs for all project steps', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CopyCostsResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /** @var list<array{blueprintTypeId: int, blueprintName: string, productTypeName: string, runs: int, cost: float, depth: int}> */
    public array $copies = [];

    public float $totalCopyCost = 0.0;
}
