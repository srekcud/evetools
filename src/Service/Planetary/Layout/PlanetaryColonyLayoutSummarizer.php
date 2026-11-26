<?php

declare(strict_types=1);

namespace App\Service\Planetary\Layout;

use App\Repository\Sde\InvTypeRepository;
use App\Service\Planetary\PlanetaryPowerCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;

final readonly class PlanetaryColonyLayoutSummarizer
{
    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private PlanetaryPowerCalculator $powerCalculator,
        private PlanetaryProductionCalculator $productionCalculator,
    ) {
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    public function summarize(array $template): array
    {
        return [];
    }
}
