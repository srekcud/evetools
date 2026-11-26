<?php

declare(strict_types=1);

namespace App\Service\Planetary\Layout;

use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;

final readonly class PlanetaryColonyLayoutParser
{
    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $json): array
    {
        return [];
    }
}
