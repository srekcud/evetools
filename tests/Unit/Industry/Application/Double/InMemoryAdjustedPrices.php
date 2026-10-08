<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\AdjustedPrices;
use App\Industry\Domain\Isk;

/**
 * Fixed ESI adjusted prices by typeId; null when the ESI publishes none (spec R5, R10).
 */
final readonly class InMemoryAdjustedPrices implements AdjustedPrices
{
    /**
     * @param array<int, float> $adjustedPrices by typeId
     */
    public function __construct(private array $adjustedPrices)
    {
    }

    public function adjustedPriceOf(int $typeId): ?Isk
    {
        if (!\array_key_exists($typeId, $this->adjustedPrices)) {
            return null;
        }

        return new Isk($this->adjustedPrices[$typeId]);
    }
}
