<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\OrderBookCoverage;

final readonly class PriceSource
{
    public const float PERCENT_PER_FRACTION = 100.0;

    private const string MARKET_JITA = 'jita';
    private const string MARKET_STRUCTURE = 'structure';
    private const string MARKET_CONTRACTS = 'contracts';
    private const string SIDE_SELL = 'sell';
    private const string SIDE_BUY = 'buy';
    private const string METHOD_WEIGHTED = 'weighted';
    private const string METHOD_LOWEST = 'lowest';
    private const string METHOD_MEDIAN = 'median';
    private const string METHOD_PERCENTILE = 'percentile';

    private function __construct(
        public string $market,
        public string $side,
        public string $method,
        public ?float $coveragePercent,
        public ?float $percentile = null,
    ) {
    }

    public static function jitaSellWeighted(?float $coveragePercent): self
    {
        return new self(self::MARKET_JITA, self::SIDE_SELL, self::METHOD_WEIGHTED, $coveragePercent);
    }

    public static function jitaSellPercentile(float $percent): self
    {
        return new self(self::MARKET_JITA, self::SIDE_SELL, self::METHOD_PERCENTILE, null, $percent);
    }

    public static function structureSellLowest(): self
    {
        return new self(self::MARKET_STRUCTURE, self::SIDE_SELL, self::METHOD_LOWEST, null);
    }

    public static function structureBuyLowest(): self
    {
        return new self(self::MARKET_STRUCTURE, self::SIDE_BUY, self::METHOD_LOWEST, null);
    }

    public static function contractsSellLowest(): self
    {
        return new self(self::MARKET_CONTRACTS, self::SIDE_SELL, self::METHOD_LOWEST, null);
    }

    public static function contractsSellMedian(): self
    {
        return new self(self::MARKET_CONTRACTS, self::SIDE_SELL, self::METHOD_MEDIAN, null);
    }

    /**
     * @param array{weightedPrice: float, coverage?: float, ordersUsed: int}|null $quote
     */
    public static function coverageOfQuote(?array $quote): ?OrderBookCoverage
    {
        $fraction = $quote['coverage'] ?? null;

        return null !== $fraction && $fraction > 0.0 ? new OrderBookCoverage($fraction) : null;
    }

    public static function partialPercentOf(?OrderBookCoverage $coverage): ?float
    {
        return null !== $coverage && $coverage->isPartial() ? PartialPricePresenter::coveragePercentOfCoverage($coverage) : null;
    }

    /**
     * @return array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}
     */
    public function toArray(): array
    {
        $label = [
            'market' => $this->market,
            'side' => $this->side,
            'method' => $this->method,
            'coveragePercent' => $this->coveragePercent,
        ];

        return self::METHOD_PERCENTILE === $this->method && null !== $this->percentile ? $label + ['percentile' => $this->percentile] : $label;
    }
}
