<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\OrderBookCoverage;
use App\Service\JitaMarketService;

final readonly class WeightedSellQuotes
{
    /**
     * @param array<string, array{weightedPrice: float, coverage?: float, ordersUsed: int}|null> $quotesByKey
     */
    private function __construct(private array $quotesByKey)
    {
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $requests
     */
    public static function read(JitaMarketService $jitaMarketService, array $requests, SellQuoteReading $reading = SellQuoteReading::WithEsiFallback): self
    {
        $distinctRequests = [];
        foreach ($requests as $request) {
            $distinctRequests[self::keyOf($request['typeId'], $request['quantity'])] = $request;
        }

        $distinctRequestList = array_values($distinctRequests);

        return new self(array_combine(
            array_keys($distinctRequests),
            match ($reading) {
                SellQuoteReading::WithEsiFallback => $jitaMarketService->getWeightedSellQuotesWithFallback($distinctRequestList),
                SellQuoteReading::CachedOnly => $jitaMarketService->getCachedWeightedSellQuotes($distinctRequestList),
            },
        ));
    }

    public function unitPriceOf(int $typeId, int $quantity): ?float
    {
        $quote = $this->quoteOf($typeId, $quantity);

        return null === $quote || null === PriceSource::coverageOfQuote($quote) ? null : $quote['weightedPrice'];
    }

    public function coverageOf(int $typeId, int $quantity): ?OrderBookCoverage
    {
        return PriceSource::coverageOfQuote($this->quoteOf($typeId, $quantity));
    }

    /**
     * @return array{weightedPrice: float, coverage?: float, ordersUsed: int}|null
     */
    private function quoteOf(int $typeId, int $quantity): ?array
    {
        if ($quantity <= 0) {
            return null;
        }

        $key = self::keyOf($typeId, $quantity);
        if (!\array_key_exists($key, $this->quotesByKey)) {
            throw new \LogicException(\sprintf('No sell quote was requested for %d units of type %d.', $quantity, $typeId));
        }

        return $this->quotesByKey[$key];
    }

    private static function keyOf(int $typeId, int $quantity): string
    {
        return $typeId . ':' . $quantity;
    }
}
