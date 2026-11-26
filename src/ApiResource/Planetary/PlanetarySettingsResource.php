<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\Entity\User;
use App\State\Processor\Planetary\PlanetarySettingsProcessor;
use App\State\Provider\Planetary\PlanetarySettingsProvider;

#[ApiResource(
    shortName: 'PlanetarySettings',
    description: 'Planetary settings of the connected user: home location, hub and coverage target',
    operations: [
        new Get(
            uriTemplate: '/planetary/settings',
            provider: PlanetarySettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get the planetary settings of the connected user', tags: ['Planetary']),
        ),
        new Patch(
            uriTemplate: '/planetary/settings',
            provider: PlanetarySettingsProvider::class,
            processor: PlanetarySettingsProcessor::class,
            read: true,
            openapi: new Model\Operation(summary: 'Update the planetary settings of the connected user', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    collectDenormalizationErrors: true,
)]
final class PlanetarySettingsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'settings';

    public ?int $homeLocationId = null;

    #[ApiProperty(writable: false)]
    public ?string $homeLocationName = null;

    public int $coverageWeeks = User::DEFAULT_PLANETARY_COVERAGE_WEEKS;

    #[ApiProperty(writable: false)]
    public int $hubStructureId = 0;

    #[ApiProperty(writable: false)]
    public string $hubStructureName = '';

    public static function forUser(User $user, int $defaultHubStructureId, string $defaultHubStructureName): self
    {
        $resource = new self();
        $resource->homeLocationId = $user->getPlanetaryHomeLocationId();
        $resource->homeLocationName = $user->getPlanetaryHomeLocationName();
        $resource->coverageWeeks = $user->getPlanetaryCoverageWeeks();

        $hubStructureId = $user->getEffectiveMarketStructureId();
        if ($hubStructureId === null) {
            $resource->hubStructureId = $defaultHubStructureId;
            $resource->hubStructureName = $defaultHubStructureName;

            return $resource;
        }

        $resource->hubStructureId = $hubStructureId;
        $resource->hubStructureName = $user->getEffectiveMarketStructureName() ?? (string) $hubStructureId;

        return $resource;
    }
}
