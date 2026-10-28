<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class PartialPrice
{
    private const string PARTIAL_MARKET_DEPTH = 'partial_market_depth';

    public string $reason;

    public function __construct(public int $typeId, public OrderBookCoverage $coverage)
    {
        $this->reason = self::PARTIAL_MARKET_DEPTH;
    }
}
