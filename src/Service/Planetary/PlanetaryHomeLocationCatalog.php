<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Dto\Assets\AssetScope;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;

final readonly class PlanetaryHomeLocationCatalog
{
    public function __construct(private AssetBrowseRepository $assetBrowseRepository)
    {
    }

    /**
     * @return list<array{locationId: int, locationName: string, solarSystemName: string|null}>
     */
    public function forUser(User $user): array
    {
        $locationsById = [];
        foreach ($user->getCharacters() as $character) {
            $characterId = $character->getId()?->toRfc4122() ?? throw new \LogicException('A loaded character is persisted');
            foreach ($this->assetBrowseRepository->summarizeLocations(AssetScope::forCharacter($characterId)) as $summary) {
                $locationsById[$summary['locationId']] ??= [
                    'locationId' => $summary['locationId'],
                    'locationName' => $summary['locationName'],
                    'solarSystemName' => $summary['solarSystemName'],
                ];
            }
        }

        $locations = array_values($locationsById);
        usort($locations, static fn (array $left, array $right): int => strcmp($left['locationName'], $right['locationName']) ?: $left['locationId'] <=> $right['locationId']);

        return $locations;
    }
}
