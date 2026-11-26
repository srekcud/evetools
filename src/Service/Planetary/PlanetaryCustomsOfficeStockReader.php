<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Dto\Assets\AssetScope;
use App\Entity\PlanetaryColony;
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
     * @param PlanetaryColony[] $colonies
     *
     * @return list<array<string, mixed>>
     */
    public function read(User $user, array $colonies): array
    {
        $coloniesByType = $this->coloniesByType($colonies);
        $typeIds = array_keys($coloniesByType);
        if ([] === $typeIds || $user->getCharacters()->isEmpty()) {
            return [];
        }

        $quantities = $this->quantitiesOfTheUser($user, $typeIds);
        if ([] === $quantities) {
            return [];
        }
        ksort($quantities);

        $sdeTypes = $this->invTypeRepository->findByTypeIds(array_keys($quantities));

        $rows = [];
        foreach ($quantities as $typeId => $quantity) {
            $volumePerUnit = isset($sdeTypes[$typeId]) ? $sdeTypes[$typeId]->getVolume() : null;
            $rows[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'quantity' => $quantity,
                'volumePerUnit' => $volumePerUnit,
                'volumeM3' => null === $volumePerUnit ? null : $volumePerUnit * $quantity,
                'probablePlanet' => $this->probablePlanet($coloniesByType[$typeId]),
            ];
        }

        return $rows;
    }

    /**
     * @param PlanetaryColony[] $colonies
     *
     * @return array<int, array<string, PlanetaryColony>> typeId => colonyId => colony
     */
    private function coloniesByType(array $colonies): array
    {
        $schematicMap = [] === $colonies ? [] : $this->schematicTypeRepository->getSchematicMap();

        $coloniesByType = [];
        foreach ($colonies as $colony) {
            $colonyId = $colony->getId()?->toRfc4122() ?? throw new \LogicException('A loaded colony is persisted');
            foreach ($colony->getPins() as $pin) {
                $typeIds = [];
                if ($pin->isExtractor()) {
                    $productTypeId = $pin->getExtractorProductTypeId();
                    if (null !== $productTypeId) {
                        $typeIds[] = $productTypeId;
                    }
                } elseif ($pin->isFactory()) {
                    $schematicId = $pin->getSchematicId();
                    $schematic = null === $schematicId ? null : ($schematicMap[$schematicId] ?? null);
                    if (null !== $schematic) {
                        $typeIds = array_keys($schematic['inputs']);
                        if (null !== $schematic['output']) {
                            $typeIds[] = $schematic['output']['typeId'];
                        }
                    }
                }
                foreach ($typeIds as $typeId) {
                    $coloniesByType[(int) $typeId][$colonyId] = $colony;
                }
            }
        }

        return $coloniesByType;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, int> typeId => quantity, above zero only
     */
    private function quantitiesOfTheUser(User $user, array $typeIds): array
    {
        $totals = [];
        foreach ($user->getCharacters() as $character) {
            $scope = AssetScope::forCharacter($character->getId()?->toRfc4122() ?? throw new \LogicException('A loaded character is persisted'));
            foreach ($this->assetBrowseRepository->customsOfficeQuantities($scope, $typeIds) as $typeId => $quantity) {
                $totals[$typeId] = ($totals[$typeId] ?? 0) + $quantity;
            }
        }

        return array_filter($totals, static fn (int $quantity): bool => $quantity > 0);
    }

    /**
     * @param array<string, PlanetaryColony> $candidates
     *
     * @return array{colonyId: string, planetName: ?string}|null
     */
    private function probablePlanet(array $candidates): ?array
    {
        if (1 !== \count($candidates)) {
            return null;
        }

        $colony = reset($candidates);

        return ['colonyId' => array_key_first($candidates), 'planetName' => $colony->getPlanetName()];
    }
}
