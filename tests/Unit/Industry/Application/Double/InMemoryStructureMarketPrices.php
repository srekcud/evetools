<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\StructureMarketPrices;
use App\Industry\Domain\Isk;

final class InMemoryStructureMarketPrices implements StructureMarketPrices
{
    /** @var list<array{int, list<int>}> */
    public array $requests = [];

    /**
     * @param array<int, array<int, float>> $unitPricesByStructureThenTypeId
     */
    public function __construct(private readonly array $unitPricesByStructureThenTypeId = [], private readonly ?\DateTimeImmutable $lastSync = null)
    {
    }

    public function isSynced(int $structureId): bool
    {
        return \array_key_exists($structureId, $this->unitPricesByStructureThenTypeId);
    }

    public function lastSyncOf(int $structureId): ?\DateTimeImmutable
    {
        return $this->isSynced($structureId) ? $this->lastSync : null;
    }

    public function unitPricesFor(int $structureId, array $typeIds): array
    {
        $this->requests[] = [$structureId, $typeIds];

        $unitPrices = [];
        foreach ($typeIds as $typeId) {
            if (isset($this->unitPricesByStructureThenTypeId[$structureId][$typeId])) {
                $unitPrices[$typeId] = new Isk($this->unitPricesByStructureThenTypeId[$structureId][$typeId]);
            }
        }

        return $unitPrices;
    }
}
