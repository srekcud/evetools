<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Pve\IncomeProvider;

#[ApiResource(
    shortName: 'PveIncome',
    description: 'PVE income data (bounties, loot sales, expenses, profit)',
    operations: [
        new Get(
            uriTemplate: '/pve/income',
            provider: IncomeProvider::class,
            openapi: new Model\Operation(
                summary: 'Get PVE income data',
                tags: ['Revenue - PVE'],
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class IncomeResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'income';

    /** @var array<string, mixed> */
    public array $period = [];

    public ?string $lastSyncAt = null;

    /** @var array<string, mixed> */
    public array $bounties = [];

    /** @var array<string, mixed> */
    public array $lootSales = [];

    /** @var array<string, mixed> */
    public array $expenses = [];

    public float $profit = 0.0;
}
