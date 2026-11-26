<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Entity\StructureMarketSnapshot;
use App\Entity\User;
use App\Repository\CachedCharacterBuyOrderRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\StructureMarketSnapshotRepository;
use App\Service\JitaMarketService;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\StructureMarketService;

final readonly class PlanetaryStockPriceReader
{
    private const int TREND_DAYS = 7;

    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private PlanetaryPaidPriceCalculator $paidPriceCalculator,
        private StructureMarketService $structureMarketService,
        private JitaMarketService $jitaMarketService,
        private StructureMarketSnapshotRepository $snapshotRepository,
        private CachedCharacterBuyOrderRepository $buyOrderRepository,
        private PlanetaryStockPriceCalculator $calculator,
    ) {
    }

    public function read(User $user, int $hubStructureId, \DateTimeImmutable $now): PlanetaryStockPriceReport
    {
        $needs = $this->inputNeedsCalculator->calculate($this->colonyRepository->findByUser($user));
        $types = $this->sortedTypes($needs['weekly']);
        $typeIds = array_keys($types);

        $paid = $this->paidPriceCalculator->calculate($user, $now);
        $paidPrices = [];
        foreach ($paid['prices'] as $price) {
            $paidPrices[$price['typeId']] = $price['paidPrice'];
        }

        $hubSells = $this->structureMarketService->getLowestSellPrices($hubStructureId, $typeIds);
        $hubBestBuys = $this->structureMarketService->getHighestBuyPrices($hubStructureId, $typeIds);
        $jitaSells = $this->jitaMarketService->getPrices($typeIds);
        $myBuyOrders = $this->highestHubBuyOrders($user, $hubStructureId, $typeIds);

        $readings = [];
        foreach ($types as $typeId => $typeName) {
            $readings[] = new PlanetaryStockPriceReading(
                $typeId,
                $typeName,
                $paidPrices[$typeId] ?? null,
                $hubSells[$typeId] ?? null,
                $jitaSells[$typeId] ?? null,
                $myBuyOrders[$typeId] ?? null,
                $hubBestBuys[$typeId] ?? null,
                $this->sellHistory($hubStructureId, $typeId),
            );
        }

        return new PlanetaryStockPriceReport(
            $this->calculator->calculate($readings),
            $this->structureMarketService->hasCachedData($hubStructureId) && $this->jitaMarketService->hasCachedData(),
            $paid['unsyncedWallets'],
        );
    }

    /**
     * @param list<array<string, mixed>> $weekly
     *
     * @return array<int, string|null> typeId => typeName, in the order of the coverage grid
     */
    private function sortedTypes(array $weekly): array
    {
        $types = [];
        foreach ($weekly as $row) {
            $typeId = $row['typeId'];
            $typeName = $row['typeName'];
            if (!\is_int($typeId)) {
                throw new \UnexpectedValueException('An integer is expected in the weekly input needs');
            }
            if (null !== $typeName && !\is_string($typeName)) {
                throw new \UnexpectedValueException('A string or null is expected in the weekly input needs');
            }
            $types[$typeId] = $typeName;
        }

        uksort($types, static function (int $leftId, int $rightId) use ($types): int {
            $leftName = $types[$leftId];
            $rightName = $types[$rightId];
            if (null === $leftName xor null === $rightName) {
                return null === $leftName ? 1 : -1;
            }

            return [$leftName, $leftId] <=> [$rightName, $rightId];
        });

        return $types;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, float> typeId => highest price of the user's buy orders at the hub
     */
    private function highestHubBuyOrders(User $user, int $hubStructureId, array $typeIds): array
    {
        $highest = [];
        $orders = $this->buyOrderRepository->findByCharactersAndTypes($user->getCharacters()->getValues(), $typeIds);
        foreach ($orders as $order) {
            if ($order->getLocationId() !== $hubStructureId) {
                continue;
            }
            $highest[$order->getTypeId()] = max($highest[$order->getTypeId()] ?? $order->getPrice(), $order->getPrice());
        }

        return $highest;
    }

    /**
     * @return list<float|null>
     */
    private function sellHistory(int $hubStructureId, int $typeId): array
    {
        return array_values(array_map(
            static fn (StructureMarketSnapshot $snapshot): ?float => $snapshot->getSellMin(),
            $this->snapshotRepository->findHistory($hubStructureId, $typeId, self::TREND_DAYS),
        ));
    }
}
