<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Pve\SyncPveProcessor;

#[ApiResource(
    shortName: 'PveSync',
    description: 'PVE data synchronization',
    operations: [
        new Post(
            uriTemplate: '/pve/sync',
            input: EmptyInput::class,
            processor: SyncPveProcessor::class,
            openapi: new Model\Operation(summary: 'Sync PVE data from ESI', tags: ['Revenue - PVE']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PveSyncResource
{
    public string $status = '';

    public string $message = '';

    /** @var array<string, int> */
    public array $imported = [];

    public ?string $lastSyncAt = null;

    /** @var list<string> */
    public array $errors = [];
}
