<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Ledger\SyncMiningProcessor;

#[ApiResource(
    shortName: 'MiningSync',
    description: 'Mining ledger synchronization',
    operations: [
        new Post(
            uriTemplate: '/ledger/mining/sync',
            input: EmptyInput::class,
            processor: SyncMiningProcessor::class,
            openapi: new Model\Operation(summary: 'Sync mining ledger from ESI', tags: ['Ledger - Mining']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MiningSyncResource
{
    public string $status = '';

    public string $message = '';

    /** @var array<string, int> */
    public array $imported = [];

    public ?string $lastSyncAt = null;

    /** @var string[] */
    public array $errors = [];
}
