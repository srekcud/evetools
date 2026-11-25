<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\PlanetaryColonyRepository;

final readonly class PlanetaryPlannerAccountReader
{
    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private CachedCharacterSkillRepository $skillRepository,
    ) {
    }

    /**
     * @return array{
     *     usedPlanets: int,
     *     characters: list<array{characterId: int, characterName: string, usedPlanets: int, maxPlanets: int|null}>,
     *     extractionYields: list<array{typeId: int, perHourPerPlanet: float, planets: int}>
     * }
     */
    public function read(User $user): array
    {
        return ['usedPlanets' => 0, 'characters' => [], 'extractionYields' => []];
    }
}
