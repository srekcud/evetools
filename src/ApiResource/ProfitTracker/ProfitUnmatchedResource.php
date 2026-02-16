<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\ProfitTracker\ProfitUnmatchedProvider;

#[ApiResource(
    shortName: 'ProfitUnmatched',
    description: 'Unmatched jobs and sales',
    operations: [
        new Get(
            uriTemplate: '/profit-tracker/unmatched',
            provider: ProfitUnmatchedProvider::class,
            openapi: new Model\Operation(summary: 'Get unmatched jobs and sales'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitUnmatchedResource
{
    /** @var list<array{jobId: int, productTypeId: int, typeName: string, runs: int, completedDate: string|null}> */
    public array $unmatchedJobs = [];

    /** @var list<array{transactionId: int, typeId: int, typeName: string, quantity: int, unitPrice: float, date: string}> */
    public array $unmatchedSales = [];
}
