<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockPriceCalculator
{
    private const int PERCENT_DECIMALS = 1;

    /**
     * @param list<PlanetaryStockPriceReading> $readings
     *
     * @return list<PlanetaryStockPriceRow>
     */
    public function calculate(array $readings): array
    {
        return array_map($this->rowOf(...), $readings);
    }

    private function rowOf(PlanetaryStockPriceReading $reading): PlanetaryStockPriceRow
    {
        $trend = $this->trendPercent($reading->hubSellHistory);

        return new PlanetaryStockPriceRow(
            $reading->typeId,
            $reading->typeName,
            $reading->paidPrice,
            $reading->hubSell,
            $reading->jitaSell,
            $this->percentOver($reading->paidPrice, $reading->hubSell),
            $reading->myBuyOrder,
            $this->percentOver($reading->myBuyOrder, $reading->hubSell),
            $trend,
            $reading->hubBestBuy,
            $this->signalOf($reading, $trend),
        );
    }

    private function percentOver(?float $price, ?float $reference): ?float
    {
        if (null === $price || null === $reference || 0.0 === $reference) {
            return null;
        }

        return round(($price / $reference - 1) * 100, self::PERCENT_DECIMALS);
    }

    /**
     * @param list<float|null> $sellHistory
     */
    private function trendPercent(array $sellHistory): ?float
    {
        $known = array_values(array_filter($sellHistory, static fn (?float $sellMin): bool => null !== $sellMin));
        if (\count($known) < 2 || 0.0 === $known[0]) {
            return null;
        }

        return round(($known[\count($known) - 1] - $known[0]) / $known[0] * 100, self::PERCENT_DECIMALS);
    }

    private function signalOf(PlanetaryStockPriceReading $reading, ?float $trend): ?PlanetaryStockPriceSignal
    {
        if (null !== $reading->paidPrice && null !== $reading->jitaSell && $reading->jitaSell < $reading->paidPrice) {
            return PlanetaryStockPriceSignal::BuyJita;
        }
        if (null !== $reading->paidPrice && null !== $reading->hubSell && $reading->hubSell < $reading->paidPrice) {
            return PlanetaryStockPriceSignal::BuyHub;
        }
        if (null !== $reading->myBuyOrder && null !== $reading->hubBestBuy && $reading->hubBestBuy > $reading->myBuyOrder) {
            return PlanetaryStockPriceSignal::Outbid;
        }
        if (null === $reading->myBuyOrder && null !== $trend && $trend < 0) {
            return PlanetaryStockPriceSignal::PlaceOrder;
        }

        return null;
    }
}
