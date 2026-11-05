<?php

declare(strict_types=1);

namespace App\Service\Industry\SellMarkets;

use App\Entity\User;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\StructureTradedVolumeService;

class SellMarketComparison
{
    public const float SELL_SHARE_OF_DAILY_VOLUME = 0.1;
    public const string JITA_MARKET_NAME = 'Jita IV - Moon 4';
    public const string JITA_KEY = 'jita';
    public const string CAPITAL_KEY = 'capital';
    public const string REGION_HISTORY_VOLUME = 'region_history';
    public const string STRUCTURE_ESTIMATE_VOLUME = 'structure_estimate';
    private const float FULL_COVERAGE = 1.0;
    private const float NO_VOLUME = 0.0;

    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly StructureTradedVolumeService $structureTradedVolumeService,
        private readonly SellerSalesFeesResolver $salesFeesResolver,
    ) {
    }

    public function compare(User $user, int $typeId, int $quantity, \DateTimeImmutable $today): SellMarketComparisonResult
    {
        $fees = $this->salesFeesResolver->forUser($user);
        $markets = [$this->jitaRow($typeId, $quantity, $fees)];

        $structureId = $user->getPreferredMarketStructureId();
        if (null !== $structureId) {
            $markets[] = $this->capitalRow($structureId, (string) $user->getPreferredMarketStructureName(), $typeId, $quantity, $today, $fees);
        }

        return new SellMarketComparisonResult($typeId, $quantity, $markets, $this->immediateSale($typeId, $quantity, $fees));
    }

    private function jitaRow(int $typeId, int $quantity, ?SalesFees $fees): SellMarketRow
    {
        $sellBook = $this->jitaMarketService->getWeightedSellPricesWithFallback([$typeId => $quantity])[$typeId] ?? null;
        $regionVolume = $this->jitaMarketService->getAverageDailyVolumes([$typeId])[$typeId] ?? null;
        $dailyVolume = null !== $regionVolume && $regionVolume > self::NO_VOLUME ? $regionVolume : null;

        return $this->row(
            self::JITA_KEY,
            self::JITA_MARKET_NAME,
            $sellBook['weightedPrice'] ?? null,
            $this->partialCoverageOf($sellBook),
            $dailyVolume,
            self::REGION_HISTORY_VOLUME,
            $quantity,
            $fees,
        );
    }

    private function capitalRow(int $structureId, string $name, int $typeId, int $quantity, \DateTimeImmutable $today, ?SalesFees $fees): SellMarketRow
    {
        $estimatedVolume = $this->structureTradedVolumeService->getAverageDailyVolumes($structureId, [$typeId], $today)[$typeId] ?? null;

        return $this->row(
            self::CAPITAL_KEY,
            $name,
            $this->structureMarketService->getLowestSellPrice($structureId, $typeId),
            null,
            $estimatedVolume,
            self::STRUCTURE_ESTIMATE_VOLUME,
            $quantity,
            $fees,
        );
    }

    private function row(string $key, string $name, ?float $unitPrice, ?float $coverage, ?float $dailyVolume, string $volumeKind, int $quantity, ?SalesFees $fees): SellMarketRow
    {
        $feeRate = null === $fees ? null : $fees->brokerFeeRate + $fees->salesTaxRate;

        return new SellMarketRow(
            $key,
            $name,
            $unitPrice,
            $coverage,
            $dailyVolume,
            $volumeKind,
            $feeRate,
            null === $unitPrice || null === $feeRate ? null : $unitPrice * (1.0 - $feeRate),
            null === $dailyVolume ? null : $quantity / (self::SELL_SHARE_OF_DAILY_VOLUME * $dailyVolume),
        );
    }

    private function immediateSale(int $typeId, int $quantity, ?SalesFees $fees): ImmediateSale
    {
        $buyBook = $this->jitaMarketService->getWeightedBuyPricesWithFallback([$typeId => $quantity])[$typeId] ?? null;
        if (null === $buyBook) {
            return new ImmediateSale(null, null, null);
        }

        $averageUnitPrice = $buyBook['weightedPrice'];

        return new ImmediateSale(
            $averageUnitPrice,
            $this->partialCoverageOf($buyBook),
            null === $fees ? null : $averageUnitPrice * (1.0 - $fees->salesTaxRate),
        );
    }

    /**
     * @param array{weightedPrice: float, coverage?: float, ordersUsed: int}|null $book
     */
    private function partialCoverageOf(?array $book): ?float
    {
        $coverage = $book['coverage'] ?? null;

        return null !== $coverage && $coverage < self::FULL_COVERAGE ? $coverage : null;
    }
}
