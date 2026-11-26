<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Industry\Stock\PersonalStockReader;

final readonly class PlanetaryProjectNeedsReader
{
    public function __construct(
        private IndustryProjectRepository $projectRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private CachedAssetRepository $assetRepository,
        private PersonalStockReader $personalStockReader,
        private PlanetaryProjectNeedsBuilder $builder,
    ) {
    }

    /**
     * @return list<PlanetaryProjectNeed>
     */
    public function read(User $user): array
    {
        return [];
    }
}
