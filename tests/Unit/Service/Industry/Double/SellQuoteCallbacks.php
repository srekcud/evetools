<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Double;

final class SellQuoteCallbacks
{
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

    public static function answering(?array $quote): \Closure
    {
        return static fn (array $requests): array => array_map(static fn (array $request): ?array => $quote, $requests);
    }

    public static function unavailableFor(\Closure $quotes, int $typeId, int $quantity): \Closure
    {
        return static function (array $requests) use ($quotes, $typeId, $quantity): array {
            $answers = $quotes($requests);
            foreach ($requests as $position => $request) {
                if ($request['typeId'] === $typeId && $request['quantity'] === $quantity) {
                    $answers[$position] = null;
                }
            }

            return $answers;
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
