<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Dto\OrderPosition;
use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;

final readonly class UndercutAssessor
{
    private const int CENTS_PER_ISK = 100;
    private const int SIGNIFICANT_DIGITS = 4;

    /**
     * @param list<array{price: float, volume: int}>|null $sellBook null when the market of the order is not followed
     */
    public function assess(float $price, ?array $sellBook): OrderPosition
    {
        if ($sellBook === null) {
            return $this->unknown(OrderPositionUnknownReason::MarketNotFollowed);
        }
        if ($sellBook === []) {
            return $this->unknown(OrderPositionUnknownReason::NoMarketData);
        }

        $cheaperOrders = 0;
        $bestPrice = $price;
        foreach ($sellBook as $entry) {
            if ($entry['price'] < $price) {
                ++$cheaperOrders;
            }
            $bestPrice = min($bestPrice, $entry['price']);
        }

        if ($cheaperOrders === 0) {
            return new OrderPosition(OrderPositionState::First, 1, $bestPrice, null, null);
        }

        return new OrderPosition(OrderPositionState::Undercut, $cheaperOrders + 1, $bestPrice, null, $this->oneTickUnder($bestPrice));
    }

    private function oneTickUnder(float $bestPrice): ?float
    {
        $highestCentsUnderBest = (int) round($bestPrice * self::CENTS_PER_ISK) - 1;
        if ($highestCentsUnderBest <= 0) {
            return null;
        }

        $tickCents = 10 ** max(0, \strlen((string) $highestCentsUnderBest) - self::SIGNIFICANT_DIGITS);

        return intdiv($highestCentsUnderBest, $tickCents) * $tickCents / self::CENTS_PER_ISK;
    }

    private function unknown(OrderPositionUnknownReason $reason): OrderPosition
    {
        return new OrderPosition(OrderPositionState::Unknown, null, null, $reason, null);
    }
}
