<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(summary: 'Trigger assets sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-market',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_market',
            openapi: new Model\Operation(summary: 'Trigger market sync (Jita + Structure)'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-pve',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_pve',
            openapi: new Model\Operation(summary: 'Trigger PVE sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-industry',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_industry',
            openapi: new Model\Operation(summary: 'Trigger industry jobs sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-wallet',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_wallet',
            openapi: new Model\Operation(summary: 'Trigger wallet transactions sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-mining',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_mining',
            openapi: new Model\Operation(summary: 'Trigger mining ledger sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-ansiblex',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_ansiblex',
            openapi: new Model\Operation(summary: 'Trigger Ansiblex sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/sync-planetary',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'sync_planetary',
            openapi: new Model\Operation(summary: 'Trigger Planetary Interaction sync'),
        ),
        new Post(
            uriTemplate: '/admin/actions/compute-profit',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'compute_profit',
            openapi: new Model\Operation(summary: 'Trigger profit computation for all users'),
        ),
        new Post(
            uriTemplate: '/admin/actions/check-market-alerts',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'check_market_alerts',
            openapi: new Model\Operation(summary: 'Check all active market price alerts'),
        ),
        new Post(
            uriTemplate: '/admin/actions/purge-notifications',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'purge_notifications',
            openapi: new Model\Operation(summary: 'Purge notifications older than 7 days'),
        ),
        new Post(
            uriTemplate: '/admin/actions/purge-market-history',
            processor: TriggerSyncProcessor::class,
            input: EmptyInput::class,
            name: 'purge_market_history',
            openapi: new Model\Operation(summary: 'Purge market history older than 365 days'),
        ),
        new Post(
            uriTemplate: '/admin/actions/retry-failed',
            processor: RetryFailedProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(summary: 'Retry failed messages'),
        ),
        new Post(
            uriTemplate: '/admin/actions/purge-failed',
            processor: PurgeFailedProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(summary: 'Purge all failed messages'),
        ),
        new Post(
            uriTemplate: '/admin/actions/clear-cache',
            processor: ClearCacheProcessor::class,
            input: EmptyInput::class,
            openapi: new Model\Operation(summary: 'Clear application cache'),
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
