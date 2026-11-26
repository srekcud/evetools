<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use Symfony\Component\Clock\ClockInterface;

class PlanetaryExtractionBalanceCalculator
{
    public function __construct(
        private readonly PlanetaryInputNeedsCalculator $inputNeeds,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calculate(PlanetaryColony $colony): array
    {
        return [];
    }
}
