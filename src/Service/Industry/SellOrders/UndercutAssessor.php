<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Dto\OrderPosition;
use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;

final readonly class UndercutAssessor
{
    public const float UNDERCUT_STEP = 100.0;

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

        $suggestedPrice = $bestPrice - self::UNDERCUT_STEP;

        return new OrderPosition(OrderPositionState::Undercut, $cheaperOrders + 1, $bestPrice, null, $suggestedPrice > 0.0 ? $suggestedPrice : null);
    }

    private function unknown(OrderPositionUnknownReason $reason): OrderPosition
    {
        return new OrderPosition(OrderPositionState::Unknown, null, null, $reason, null);
    }
}
