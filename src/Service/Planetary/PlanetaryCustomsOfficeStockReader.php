<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\TypeNameResolver;

class PlanetaryCustomsOfficeStockReader
{
    public function __construct(
        private readonly AssetBrowseRepository $assetBrowseRepository,
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * @param \App\Entity\PlanetaryColony[] $colonies
     *
     * @return list<array<string, mixed>>
     */
    public function read(User $user, array $colonies): array
    {
        return [];
    }
}
