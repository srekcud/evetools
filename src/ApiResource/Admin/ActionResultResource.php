<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Admin\ClearCacheProcessor;
use App\State\Processor\Admin\PurgeFailedProcessor;
use App\State\Processor\Admin\RetryFailedProcessor;
use App\State\Processor\Admin\TriggerSyncProcessor;

#[ApiResource(
    shortName: 'AdminAction',
    description: 'Admin actions (sync, retry, purge)',
    operations: [
        new Post(
            uriTemplate: '/admin/actions/sync-assets',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_assets',
            openapiContext: [
                'summary' => 'Trigger assets sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-market',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_market',
            openapiContext: [
                'summary' => 'Trigger market sync (Jita + Structure)',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-pve',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_pve',
            openapiContext: [
                'summary' => 'Trigger PVE sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-industry',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_industry',
            openapiContext: [
                'summary' => 'Trigger industry jobs sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-wallet',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_wallet',
            openapiContext: [
                'summary' => 'Trigger wallet transactions sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-mining',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_mining',
            openapiContext: [
                'summary' => 'Trigger mining ledger sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-ansiblex',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_ansiblex',
            openapiContext: [
                'summary' => 'Trigger Ansiblex sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-planetary',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_planetary',
            openapiContext: [
                'summary' => 'Trigger Planetary Interaction sync',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/retry-failed',
            processor: RetryFailedProcessor::class,
            input: EmptyInput::class,
            openapiContext: [
                'summary' => 'Retry failed messages',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/purge-failed',
            processor: PurgeFailedProcessor::class,
            input: EmptyInput::class,
            openapiContext: [
                'summary' => 'Purge all failed messages',
            ],
        ),
        new Post(
            uriTemplate: '/admin/actions/clear-cache',
            processor: ClearCacheProcessor::class,
            input: EmptyInput::class,
            openapiContext: [
                'summary' => 'Clear application cache',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ActionResultResource
{
    public bool $success = false;

    public string $message = '';

    public ?string $output = null;

    public ?int $deleted = null;
}
