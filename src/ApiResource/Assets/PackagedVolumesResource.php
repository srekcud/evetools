<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\PackagedVolumesProvider;

#[ApiResource(
    shortName: 'PackagedVolumes',
    description: 'Packaged (repackaged) volume per item type, refreshed weekly from ESI',
    operations: [
        new Get(
            uriTemplate: '/me/assets/packaged-volumes',
            provider: PackagedVolumesProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the packaged volume of every known type',
                description: 'Volumes in m3 keyed by type id, the station container types left out of totals, and the newest refresh date',
                tags: ['Inventory'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class PackagedVolumesResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'packaged-volumes';

    /** @var array<int, float> */
    public array $volumes = [];

    /** @var list<int> */
    public array $excludedTypeIds = [];

    public ?string $refreshedAt = null;
}
