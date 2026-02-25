<?php

declare(strict_types=1);

namespace App\ApiResource\UserSettings;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\State\Processor\UserSettings\UpdateUserSettingsProcessor;
use App\State\Provider\UserSettings\UserSettingsProvider;

#[ApiResource(
    shortName: 'UserSettings',
    description: 'User settings (market structure preference)',
    operations: [
        new Get(
            uriTemplate: '/me/settings',
            provider: UserSettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get user settings', tags: ['User Settings']),
        ),
        new Patch(
            uriTemplate: '/me/settings',
            provider: UserSettingsProvider::class,
            processor: UpdateUserSettingsProcessor::class,
            openapi: new Model\Operation(
                summary: 'Update user settings',
                tags: ['User Settings'],
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'preferredMarketStructureId' => [
                                        'type' => 'integer',
                                        'nullable' => true,
                                        'description' => 'Preferred structure ID for market price comparison (null to reset to default)',
                                    ],
                                    'preferredMarketStructureName' => [
                                        'type' => 'string',
                                        'nullable' => true,
                                        'description' => 'Preferred structure name (for display)',
                                    ],
                                    'marketStructures' => [
                                        'type' => 'array',
                                        'description' => 'List of favorite market structures',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer'],
                                                'name' => ['type' => 'string'],
                                            ],
                                        ],
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
class UserSettingsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'me';

    public ?int $preferredMarketStructureId = null;

    public ?string $preferredMarketStructureName = null;

    /** Default structure ID from app configuration. */
    public int $defaultMarketStructureId;

    /** Default structure name from app configuration. */
    public string $defaultMarketStructureName;

    /** Effective structure ID (user preference or default). */
    public int $effectiveMarketStructureId;

    /** Effective structure name (user preference or default). */
    public string $effectiveMarketStructureName;

    /** @var list<array{id: int, name: string}> User's favorite market structures. */
    public array $marketStructures = [];
}
