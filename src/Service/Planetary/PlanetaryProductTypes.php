<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\TypeNameResolver;

class PlanetaryProductTypes
{
    public function __construct(
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * @return array<int, string> type name of each product run by a factory of the user's colonies, by type id
     */
    public function of(User $user): array
    {
        $schematicMap = $this->schematicTypeRepository->getSchematicMap();

        $typeIds = [];
        foreach ($this->colonyRepository->findByUser($user) as $colony) {
            foreach ($colony->getPins() as $pin) {
                $schematicId = $pin->getSchematicId();
                if ($schematicId === null || !$pin->isFactory()) {
                    continue;
                }
                $outputTypeId = $schematicMap[$schematicId]['output']['typeId'] ?? null;
                if ($outputTypeId !== null) {
                    $typeIds[$outputTypeId] = $outputTypeId;
                }
            }
        }

        return $this->typeNameResolver->findNames(array_values($typeIds));
    }
}
