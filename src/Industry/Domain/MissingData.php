<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Why a cost is unknown (spec R10): the data that was absent, and the typeId it belongs to when there is one.
 */
final readonly class MissingData
{
    private const string ADJUSTED_PRICE = 'adjusted_price';
    private const string COST_INDEX = 'cost_index';

    private function __construct(public string $reason, public ?int $typeId)
    {
    }

    public static function adjustedPrice(int $typeId): self
    {
        return new self(self::ADJUSTED_PRICE, $typeId);
    }

    public static function costIndex(): self
    {
        return new self(self::COST_INDEX, null);
    }
}
