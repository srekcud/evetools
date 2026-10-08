<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\SystemCostIndices;
use App\Industry\Domain\ActivityKind;

/**
 * Fixed cost indices by solar system and activity; null when the ESI has no index (spec R6, R10).
 */
final readonly class InMemorySystemCostIndices implements SystemCostIndices
{
    /**
     * @param array<int, array<string, float>> $costIndices by solar system id, then by ActivityKind name
     */
    public function __construct(private array $costIndices)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function costIndexOf(int $solarSystemId, ActivityKind $activity): ?float
    {
        return $this->costIndices[$solarSystemId][$activity->name] ?? null;
    }
}
