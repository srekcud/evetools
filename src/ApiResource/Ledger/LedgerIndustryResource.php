<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Ledger\LedgerIndustryProvider;

#[ApiResource(
    shortName: 'LedgerIndustry',
    description: 'Real profit of completed industry projects, by month and by product',
    operations: [
        new Get(
            uriTemplate: '/ledger/industry',
            provider: LedgerIndustryProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the industry revenue of the ledger',
                tags: ['Ledger'],
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class LedgerIndustryResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'industry';

    /** @var array<string, mixed> */
    public array $period = [];

    /** @var array<string, mixed> */
    public array $totals = [];

    /** @var list<array<string, mixed>> */
    public array $months = [];

    /** @var list<array<string, mixed>> */
    public array $products = [];
}
