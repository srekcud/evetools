<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\ProfitTracker\ProfitItemDetailProvider;

#[ApiResource(
    shortName: 'ProfitItemDetail',
    description: 'Detailed profit breakdown for a specific item',
    operations: [
        new Get(
            uriTemplate: '/profit-tracker/items/{typeId}',
            provider: ProfitItemDetailProvider::class,
            openapi: new Model\Operation(summary: 'Get item profit detail'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitItemDetailResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId = 0;

    /** @var array{materialCost: float, jobInstallCost: float, taxAmount: float, totalCost: float} */
    public array $costBreakdown = [
        'materialCost' => 0.0,
        'jobInstallCost' => 0.0,
        'taxAmount' => 0.0,
        'totalCost' => 0.0,
    ];

    /** @var list<array{jobId: int|null, transactionId: int|null, quantitySold: int, revenue: float, materialCost: float, jobInstallCost: float, taxAmount: float, profit: float, matchedAt: string}> */
    public array $matches = [];

    /** @var list<array{date: string, profit: float, revenue: float, margin: float}> */
    public array $marginTrend = [];
}
