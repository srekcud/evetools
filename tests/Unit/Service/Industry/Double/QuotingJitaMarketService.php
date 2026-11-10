<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Double;

use App\Service\JitaMarketService;

class QuotingJitaMarketService extends JitaMarketService
{
    public function __construct()
    {
    }

    public function getWeightedSellQuotesWithFallback(array $requests): array
    {
        throw new \LogicException('Configure this method on the test double.');
    }

    public static function completeQuotesAt(array $unitPrices): \Closure
    {
        return static function (array $requests) use ($unitPrices): array {
            $quotes = [];
            foreach ($requests as $request) {
                $unitPrice = $unitPrices[$request['typeId']] ?? null;
                $quotes[] = $unitPrice === null ? null : ['weightedPrice' => $unitPrice, 'coverage' => 1.0, 'ordersUsed' => 1];
            }

            return $quotes;
        };
    }

    public static function weighedFrom(array $sellBooks): \Closure
    {
        return static function (array $requests) use ($sellBooks): array {
            $quotes = [];
            foreach ($requests as $request) {
                $quotes[] = self::weigh($sellBooks[$request['typeId']] ?? [], $request['quantity']);
            }

            return $quotes;
        };
    }

    private static function weigh(array $orders, int $quantity): ?array
    {
        $cost = 0.0;
        $covered = 0;
        $ordersUsed = 0;
        foreach ($orders as [$price, $volume]) {
            $taken = min($quantity - $covered, $volume);
            if ($taken <= 0) {
                break;
            }
            $cost += $taken * $price;
            $covered += $taken;
            ++$ordersUsed;
        }

        return $covered === 0 ? null : ['weightedPrice' => $cost / $covered, 'coverage' => $covered / $quantity, 'ordersUsed' => $ordersUsed];
    }
}
