<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
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
            openapiContext: [
                'summary' => 'Sync PVE data from ESI',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PveSyncResource
{
    public string $status = '';

    public string $message = '';

    public array $imported = [];

    public ?string $lastSyncAt = null;

    public array $errors = [];
}
