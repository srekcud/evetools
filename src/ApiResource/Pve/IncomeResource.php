<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Pve\IncomeProvider;

#[ApiResource(
    shortName: 'PveIncome',
    description: 'PVE income data (bounties, loot sales, expenses, profit)',
    operations: [
        new Get(
            uriTemplate: '/pve/income',
            provider: IncomeProvider::class,
            openapiContext: [
                'summary' => 'Get PVE income data',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to include (default: 30)'],
                ],
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class IncomeResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'income';

    public array $period = [];

    public ?string $lastSyncAt = null;

    public array $bounties = [];

    public array $lootSales = [];

    public array $expenses = [];

    public float $profit = 0.0;
}
