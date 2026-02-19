<?php

declare(strict_types=1);

namespace App\ApiResource\UserSettings;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\UserSettings\AvailableStructuresProvider;

#[ApiResource(
    shortName: 'AvailableStructure',
    description: 'Structures available for market comparison',
    operations: [
        new GetCollection(
            uriTemplate: '/me/settings/available-structures',
            provider: AvailableStructuresProvider::class,
            openapi: new Model\Operation(
                summary: 'List structures available for market comparison',
                tags: ['User Settings'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AvailableStructureResource
{
    public int $structureId;

    public string $structureName;

    public bool $isDefault = false;

    public bool $hasCachedData = false;

    public ?string $lastSyncAt = null;
}
