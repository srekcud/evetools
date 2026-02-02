<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Pve\CreateExpenseInput;
use App\ApiResource\Input\Pve\ImportExpensesInput;
use App\State\Processor\Pve\CreateExpenseProcessor;
use App\State\Processor\Pve\DeleteExpenseProcessor;
use App\State\Processor\Pve\ImportExpensesProcessor;
use App\State\Provider\Pve\ExpenseCollectionProvider;
use App\State\Provider\Pve\ExpenseProvider;

#[ApiResource(
    shortName: 'PveExpense',
    description: 'PVE expenses (fuel, ammo, beacons, etc.)',
    operations: [
        new GetCollection(
            uriTemplate: '/pve/expenses',
            provider: ExpenseCollectionProvider::class,
            openapiContext: [
                'summary' => 'List PVE expenses',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to include (default: 30)'],
                ],
            ],
        ),
        new Post(
            uriTemplate: '/pve/expenses',
            processor: CreateExpenseProcessor::class,
            input: CreateExpenseInput::class,
            openapiContext: [
                'summary' => 'Create a PVE expense',
            ],
        ),
        new Delete(
            uriTemplate: '/pve/expenses/{id}',
            provider: ExpenseProvider::class,
            processor: DeleteExpenseProcessor::class,
            openapiContext: [
                'summary' => 'Delete a PVE expense',
            ],
        ),
        new Post(
            uriTemplate: '/pve/import-expenses',
            processor: ImportExpensesProcessor::class,
            input: ImportExpensesInput::class,
            output: ImportResultResource::class,
            openapiContext: [
                'summary' => 'Import scanned expenses',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ExpenseResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $type = '';

    public string $description = '';

    public float $amount = 0.0;

    public string $date = '';
}
