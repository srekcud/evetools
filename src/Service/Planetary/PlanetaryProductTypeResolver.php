<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Exception\PlanetaryTierMissingFromSdeException;
use App\Exception\PlanetSchematicIncompleteException;
use App\Repository\Sde\PlanetSchematicTypeRepository;

final readonly class PlanetaryProductTypeResolver
{
    private const array FACTORY_TIERS = ['P2', 'P3', 'P4'];

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
        $typeIds = [];
        foreach ($this->schematicTypeRepository->getSchematicMap() as $schematicId => $schematic) {
            if ($schematic['output'] === null) {
                throw new PlanetSchematicIncompleteException($schematicId);
            }

            $outputTypeId = $schematic['output']['typeId'];
            $tier = $this->productionCalculator->findProductTier($outputTypeId) ?? throw new PlanetaryTierMissingFromSdeException($outputTypeId);
            if (in_array($tier, self::FACTORY_TIERS, true)) {
                $typeIds[$outputTypeId] = $outputTypeId;
            }
        }
        ksort($typeIds);

        return array_values($typeIds);
    }
}
