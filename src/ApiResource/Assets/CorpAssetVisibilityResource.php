<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Assets\UpdateCorpVisibilityInput;
use App\State\Processor\Assets\UpdateCorpVisibilityProcessor;
use App\State\Provider\Assets\CorpAssetVisibilityProvider;

#[ApiResource(
    shortName: 'CorpAssetVisibility',
    description: 'Corporation asset division visibility configuration',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/visibility',
            provider: CorpAssetVisibilityProvider::class,
            openapi: new Model\Operation(
                summary: 'Get corporation asset visibility config',
                description: 'Returns which hangar divisions are visible to corp members',
                tags: ['Inventory'],
            ),
        ),
        new Put(
            uriTemplate: '/me/corporation/assets/visibility',
            provider: CorpAssetVisibilityProvider::class,
            processor: UpdateCorpVisibilityProcessor::class,
            input: UpdateCorpVisibilityInput::class,
            openapi: new Model\Operation(
                summary: 'Update corporation asset visibility',
                description: 'Set which hangar divisions (1-7) are visible to corp members. Director only.',
                tags: ['Inventory'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CorpAssetVisibilityResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'visibility';

    /** @var int[] Division numbers that are currently visible */
    public array $visibleDivisions = [];

    /** @var array<int, string> All 7 divisions with their ESI names */
    public array $allDivisions = [];

    public bool $isDirector = false;

    public ?string $configuredByName = null;

    public ?string $updatedAt = null;
}
