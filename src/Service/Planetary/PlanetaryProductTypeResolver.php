<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Repository\Sde\PlanetSchematicTypeRepository;

final readonly class PlanetaryProductTypeResolver
{
    public function __construct(
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private PlanetaryProductionCalculator $productionCalculator,
    ) {
    }

    /**
     * @return list<int>
     */
    public function factoryOutputTypeIds(): array
    {
        return [];
    }
}
