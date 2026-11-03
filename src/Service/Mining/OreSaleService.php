<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;

final readonly class OreSaleService
{
    private const string JITA_BUY = 'jita_buy';
    private const string JITA_SELL = 'jita_sell';
    private const string STRUCTURE_BUY = 'structure_buy';
    private const string STRUCTURE_SELL = 'structure_sell';

    public function __construct(
        private CachedAssetRepository $cachedAssetRepository,
        private InvTypeRepository $invTypeRepository,
        private JitaMarketService $jitaMarketService,
        private StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(User $user, ?int $structureId): array
    {
        $heldQuantities = array_filter(
            $this->cachedAssetRepository->getAggregatedQuantitiesByUser($user),
            static fn (int $quantity): bool => $quantity > 0,
        );
        $types = $this->invTypeRepository->findCompressedMineableTypes(array_keys($heldQuantities));
        if ($types === []) {
            return ['rows' => [], 'totals' => $this->totalsOf([])];
        }

        $typeIds = array_map(static fn (InvType $type): int => $type->getTypeId(), $types);
        $prices = $this->pricesOf($typeIds, $structureId);

        $rows = array_map(
            fn (InvType $type): array => $this->rowOf($type, $heldQuantities[$type->getTypeId()], $prices),
            $types,
        );
        usort($rows, $this->byValueDescendingThenName(...));

        return ['rows' => $rows, 'totals' => $this->totalsOf($rows)];
    }

    /**
     * @param list<int> $typeIds
     * @return array<string, array<int, float|null>>
     */
    private function pricesOf(array $typeIds, ?int $structureId): array
    {
        $prices = [
            self::JITA_BUY => $this->jitaMarketService->getBuyPricesWithFallback($typeIds),
            self::JITA_SELL => $this->jitaMarketService->getPricesWithFallback($typeIds),
            self::STRUCTURE_BUY => [],
            self::STRUCTURE_SELL => [],
        ];
        if ($structureId !== null) {
            $prices[self::STRUCTURE_BUY] = $this->structureMarketService->getHighestBuyPrices($structureId, $typeIds);
            $prices[self::STRUCTURE_SELL] = $this->structureMarketService->getLowestSellPrices($structureId, $typeIds);
        }

        return $prices;
    }

    /**
     * @param array<string, array<int, float|null>> $prices
     * @return array<string, mixed>
     */
    private function rowOf(InvType $type, int $quantity, array $prices): array
    {
        $typeId = $type->getTypeId();
        $unitVolume = $type->getVolume();
        $pricesBySource = [];
        foreach ($prices as $source => $pricesOfSource) {
            $pricesBySource[$source] = $pricesOfSource[$typeId] ?? null;
        }
        [$bestPrice, $bestSource] = $this->bestOf($pricesBySource);

        return [
            'typeId' => $typeId,
            'typeName' => $type->getTypeName(),
            'quantity' => $quantity,
            'unitVolume' => $unitVolume,
            'totalVolume' => $unitVolume === null ? null : $quantity * $unitVolume,
            'jitaBuyPrice' => $pricesBySource[self::JITA_BUY],
            'jitaSellPrice' => $pricesBySource[self::JITA_SELL],
            'structureBuyPrice' => $pricesBySource[self::STRUCTURE_BUY],
            'structureSellPrice' => $pricesBySource[self::STRUCTURE_SELL],
            'bestPrice' => $bestPrice,
            'bestSource' => $bestSource,
            'totalValue' => $bestPrice === null ? null : $quantity * $bestPrice,
        ];
    }

    /**
     * @param array<string, float|null> $pricesBySource
     * @return array{0: float|null, 1: string|null}
     */
    private function bestOf(array $pricesBySource): array
    {
        $bestPrice = null;
        $bestSource = null;
        foreach ($pricesBySource as $source => $price) {
            if ($price !== null && ($bestPrice === null || $price > $bestPrice)) {
                $bestPrice = $price;
                $bestSource = $source;
            }
        }

        return [$bestPrice, $bestSource];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function byValueDescendingThenName(array $left, array $right): int
    {
        if ($left['totalValue'] !== $right['totalValue']) {
            if ($left['totalValue'] === null) {
                return 1;
            }
            if ($right['totalValue'] === null) {
                return -1;
            }

            return $right['totalValue'] <=> $left['totalValue'];
        }

        return strcmp($left['typeName'], $right['typeName']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function totalsOf(array $rows): array
    {
        $missingPriceRows = array_filter($rows, static fn (array $row): bool => $row['totalValue'] === null);
        $missingVolumeRows = array_filter($rows, static fn (array $row): bool => $row['totalVolume'] === null);

        return [
            'totalQuantity' => array_sum(array_column($rows, 'quantity')),
            'totalVolume' => $missingVolumeRows === [] ? (float) array_sum(array_column($rows, 'totalVolume')) : null,
            'totalValue' => $missingPriceRows === [] ? (float) array_sum(array_column($rows, 'totalValue')) : null,
            'missingPriceTypeIds' => array_column($missingPriceRows, 'typeId'),
            'missingVolumeTypeIds' => array_column($missingVolumeRows, 'typeId'),
        ];
    }
}
