<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\PlanetaryColonyRepository;

final readonly class PlanetaryPlannerAccountReader
{
    private const int BASE_PLANETS = 1;

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
        $colonies = $this->colonyRepository->findByUser($user);

        return [
            'usedPlanets' => \count($colonies),
            'characters' => $this->readCharacters($user, $colonies),
            'extractionYields' => $this->readExtractionYields($colonies),
        ];
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return list<array{characterId: int, characterName: string, usedPlanets: int, maxPlanets: int|null}>
     */
    private function readCharacters(User $user, array $colonies): array
    {
        $characters = [];
        foreach ($user->getCharacters() as $character) {
            $characters[] = [
                'characterId' => $character->getEveCharacterId(),
                'characterName' => $character->getName(),
                'usedPlanets' => $this->countColoniesOf($character, $colonies),
                'maxPlanets' => $this->maxPlanetsOf($character),
            ];
        }

        usort($characters, static fn (array $left, array $right): int => [$left['characterName'], $left['characterId']] <=> [$right['characterName'], $right['characterId']]);

        return $characters;
    }

    /**
     * @param PlanetaryColony[] $colonies
     */
    private function countColoniesOf(Character $character, array $colonies): int
    {
        return \count(array_filter(
            $colonies,
            static fn (PlanetaryColony $colony): bool => $colony->getCharacter()->getEveCharacterId() === $character->getEveCharacterId(),
        ));
    }

    private function maxPlanetsOf(Character $character): ?int
    {
        $levels = $this->skillRepository->findLevelsByEveCharacterId($character->getEveCharacterId());
        if (!isset($levels[CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION])) {
            return null;
        }

        return self::BASE_PLANETS + $levels[CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION];
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return list<array{typeId: int, perHourPerPlanet: float, planets: int}>
     */
    private function readExtractionYields(array $colonies): array
    {
        $yieldsByResource = [];
        foreach ($colonies as $colony) {
            foreach ($this->colonyYieldsByResource($colony) as $resourceTypeId => $colonyYield) {
                $yieldsByResource[$resourceTypeId][] = $colonyYield;
            }
        }
        ksort($yieldsByResource);

        $extractionYields = [];
        foreach ($yieldsByResource as $resourceTypeId => $colonyYields) {
            $extractionYields[] = [
                'typeId' => $resourceTypeId,
                'perHourPerPlanet' => array_sum($colonyYields) / \count($colonyYields),
                'planets' => \count($colonyYields),
            ];
        }

        return $extractionYields;
    }

    /**
     * @return array<int, float>
     */
    private function colonyYieldsByResource(PlanetaryColony $colony): array
    {
        $yields = [];
        $incompleteResources = [];
        foreach ($colony->getPins() as $pin) {
            $resourceTypeId = $pin->getExtractorProductTypeId();
            if ($resourceTypeId === null) {
                continue;
            }
            $pinYield = $pin->getExtractorYieldPerHour();
            if ($pinYield === null) {
                $incompleteResources[$resourceTypeId] = true;
                continue;
            }
            $yields[$resourceTypeId] = isset($yields[$resourceTypeId]) ? $yields[$resourceTypeId] + $pinYield : $pinYield;
        }

        return array_diff_key($yields, $incompleteResources);
    }
}
