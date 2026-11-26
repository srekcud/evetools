<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\User;
use App\Repository\AssetBrowseRepository;

final readonly class PlanetaryHomeLocationCatalog
{
    public function __construct(private AssetBrowseRepository $assetBrowseRepository)
    {
    }

    /**
     * @return list<array{locationId: int, locationName: string, solarSystemName: string|null}>
     */
    public function forUser(User $user): array
    {
        return [];
    }
}
