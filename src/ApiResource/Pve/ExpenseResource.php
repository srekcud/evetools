<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(
                summary: 'List PVE expenses',
                tags: ['Revenue - PVE'],
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/pve/expenses',
            processor: CreateExpenseProcessor::class,
            input: CreateExpenseInput::class,
            openapi: new Model\Operation(summary: 'Create a PVE expense', tags: ['Revenue - PVE']),
        ),
        new Delete(
            uriTemplate: '/pve/expenses/{id}',
            provider: ExpenseProvider::class,
            processor: DeleteExpenseProcessor::class,
            openapi: new Model\Operation(summary: 'Delete a PVE expense', tags: ['Revenue - PVE']),
        ),
        new Post(
            uriTemplate: '/pve/import-expenses',
            processor: ImportExpensesProcessor::class,
            input: ImportExpensesInput::class,
            output: ImportResultResource::class,
            openapi: new Model\Operation(summary: 'Import scanned expenses', tags: ['Revenue - PVE']),
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
