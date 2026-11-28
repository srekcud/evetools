<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Dto\Assets\AssetScope;
use App\Entity\CachedCharacterBuyOrder;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\CachedCharacterBuyOrderRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Stock\PersonalStockReader;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;

final readonly class PlanetaryStockReader
{
    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private InvTypeRepository $invTypeRepository,
        private AssetBrowseRepository $assetBrowseRepository,
        private PersonalStockReader $personalStockReader,
        private CachedCharacterBuyOrderRepository $buyOrderRepository,
        private PlanetaryStockCalculator $calculator,
    ) {
    }

    public function read(User $user, PlanetaryStockSettings $settings): PlanetaryStock
    {
        $needs = $this->inputNeedsCalculator->calculate($this->colonyRepository->findByUser($user));
        $weekly = array_values(array_filter(
            $needs['weekly'],
            static fn (array $row): bool => !\in_array(self::intOf($row['typeId']), $settings->excludedTypeIds, true),
        ));
        $typeIds = array_map(static fn (array $row): int => self::intOf($row['typeId']), $weekly);
        $sdeTypes = [] === $typeIds ? [] : $this->invTypeRepository->findByTypeIds($typeIds);

        $weeklyNeeds = array_map(
            static fn (array $row): PlanetaryWeeklyNeed => new PlanetaryWeeklyNeed(
                self::intOf($row['typeId']),
                self::nullableStringOf($row['typeName']),
                self::nullableFloatOf($row['weeklyNetNeed']),
                ($sdeTypes[self::intOf($row['typeId'])] ?? null)?->getVolume(),
            ),
            $weekly,
        );

        $characters = $user->getCharacters()->getValues();

        return $this->calculator->calculate(new PlanetaryStockReadings(
            $weeklyNeeds,
            $settings->homeLocationId,
            $settings->hubStructureId,
            $settings->coverageWeeks,
            $this->quantitiesByRootLocation($user, $settings, $typeIds),
            $this->personalStockReader->containerContentsOf($user),
            array_map(
                static fn (CachedCharacterBuyOrder $order): PlanetaryBuyOrder => new PlanetaryBuyOrder($order->getTypeId(), $order->getLocationId(), $order->getVolumeRemain()),
                $this->buyOrderRepository->findByCharactersAndTypes($characters, $typeIds),
            ),
            $needs['complete'],
            $this->customsOfficeQuantities($user, $typeIds),
            $this->elsewhereQuantities($user, $settings, $typeIds),
        ));
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, array<int, int>>
     */
    private function quantitiesByRootLocation(User $user, PlanetaryStockSettings $settings, array $typeIds): array
    {
        $rootLocationIds = $this->rootLocationIds($settings);

        $totals = [];
        foreach ($user->getCharacters() as $character) {
            $quantities = $this->assetBrowseRepository->quantitiesByRootLocation(
                $this->scopeOf($character),
                $rootLocationIds,
                $typeIds,
            );
            foreach ($quantities as $locationId => $byType) {
                foreach ($byType as $typeId => $quantity) {
                    $totals[$locationId][$typeId] = ($totals[$locationId][$typeId] ?? 0) + $quantity;
                }
            }
        }

        return $totals;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, int> typeId => quantity
     */
    private function customsOfficeQuantities(User $user, array $typeIds): array
    {
        $totals = [];
        foreach ($user->getCharacters() as $character) {
            $quantities = $this->assetBrowseRepository->customsOfficeQuantities(
                $this->scopeOf($character),
                $typeIds,
            );
            foreach ($quantities as $typeId => $quantity) {
                $totals[$typeId] = ($totals[$typeId] ?? 0) + $quantity;
            }
        }

        return $totals;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, int> typeId => quantity
     */
    private function elsewhereQuantities(User $user, PlanetaryStockSettings $settings, array $typeIds): array
    {
        $excludedRootLocationIds = $this->rootLocationIds($settings);

        $totals = [];
        foreach ($user->getCharacters() as $character) {
            $quantities = $this->assetBrowseRepository->elsewhereQuantities(
                $this->scopeOf($character),
                $excludedRootLocationIds,
                $typeIds,
            );
            foreach ($quantities as $typeId => $quantity) {
                $totals[$typeId] = ($totals[$typeId] ?? 0) + $quantity;
            }
        }

        return $totals;
    }

    /**
     * @return list<int>
     */
    private function rootLocationIds(PlanetaryStockSettings $settings): array
    {
        return array_values(array_unique(array_filter(
            [$settings->homeLocationId, $settings->hubStructureId],
            static fn (?int $locationId): bool => null !== $locationId,
        )));
    }

    private function scopeOf(Character $character): AssetScope
    {
        return AssetScope::forCharacter($character->getId()?->toRfc4122() ?? throw new \LogicException('A loaded character is persisted'));
    }

    private static function intOf(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException('An integer is expected in the weekly input needs');
        }

        return $value;
    }

    private static function nullableStringOf(mixed $value): ?string
    {
        if (null !== $value && !\is_string($value)) {
            throw new \UnexpectedValueException('A string or null is expected in the weekly input needs');
        }

        return $value;
    }

    private static function nullableFloatOf(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }
        if (!\is_float($value) && !\is_int($value)) {
            throw new \UnexpectedValueException('A number or null is expected in the weekly input needs');
        }

        return (float) $value;
    }
}
