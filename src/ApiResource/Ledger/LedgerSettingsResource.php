<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\State\Processor\Ledger\UpdateLedgerSettingsProcessor;
use App\State\Provider\Ledger\LedgerSettingsProvider;

#[ApiResource(
    shortName: 'LedgerSettings',
    description: 'Ledger user settings',
    operations: [
        new Get(
            uriTemplate: '/ledger/settings',
            provider: LedgerSettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get ledger settings'),
        ),
        new Patch(
            uriTemplate: '/ledger/settings',
            provider: LedgerSettingsProvider::class,
            processor: UpdateLedgerSettingsProcessor::class,
            openapi: new Model\Operation(
                summary: 'Update ledger settings',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'corpProjectAccounting' => [
                                        'type' => 'string',
                                        'enum' => ['pve', 'mining'],
                                        'description' => 'Where to count corp project contributions',
                                    ],
                                    'autoSyncEnabled' => [
                                        'type' => 'boolean',
                                        'description' => 'Enable automatic sync',
                                    ],
                                    'excludedTypeIds' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'integer'],
                                        'description' => 'Ore type IDs to exclude from stats',
                                    ],
                                    'defaultSoldTypeIds' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'integer'],
                                        'description' => 'Ore type IDs considered sold by default',
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class LedgerSettingsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'settings';

    /**
     * Where to count corp project contributions: 'pve' or 'mining'.
     */
    public string $corpProjectAccounting = 'pve';

    /**
     * Enable automatic sync.
     */
    public bool $autoSyncEnabled = true;

    /**
     * Last mining sync timestamp.
     */
    public ?string $lastMiningSyncAt = null;

    /**
     * Ore type IDs to exclude from mining stats.
     * @var int[]
     */
    public array $excludedTypeIds = [];

    /**
     * Ore type IDs considered sold by default.
     * @var int[]
     */
    public array $defaultSoldTypeIds = [];

    /**
     * Last updated timestamp.
     */
    public string $updatedAt;
}
