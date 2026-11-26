<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\TypeNameResolver;
use Symfony\Component\Clock\ClockInterface;

class PlanetaryPendingStockCalculator
{
    public function __construct(
        private readonly PlanetSchematicRepository $schematicRepository,
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly ClockInterface $clock,
        private readonly ExtractorYieldCalculator $extractorYield,
    ) {
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return array{storages: list<array<string, mixed>>, pendingOutputs: list<array<string, mixed>>, complete: bool}
     */
    public function calculate(array $colonies): array
    {
        return ['storages' => [], 'pendingOutputs' => [], 'complete' => true];
    }
}
