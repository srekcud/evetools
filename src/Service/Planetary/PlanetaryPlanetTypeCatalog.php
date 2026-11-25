<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class PlanetaryPlanetTypeCatalog
{
    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private DgmTypeAttributeRepository $attributeRepository,
    ) {
    }

    /**
     * @return array{
     *     planetTypes: list<array{name: string, commandCenter: array{cpu: int, pg: int}, launchpad: array{cpu: int, pg: int, capacity: float}, facilities: array{basic: array{cpu: int, pg: int}|null, advanced: array{cpu: int, pg: int}|null, highTech: array{cpu: int, pg: int}|null}}>,
     *     extractionPlanetTypes: list<array{typeId: int, planetTypes: list<string>}>
     * }
     */
    public function build(): array
    {
        return ['planetTypes' => [], 'extractionPlanetTypes' => []];
    }
}
