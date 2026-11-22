<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\StructureMarketPrices;
use App\Industry\Domain\Isk;

final class InMemoryStructureMarketPrices implements StructureMarketPrices
{
    /** @var list<array{int, list<int>}> */
    public array $requests = [];

    /** @var list<array{int, list<int>}> */
    public array $volumeRequests = [];

    /**
     * @param array<int, array<int, float>> $unitPricesByStructureThenTypeId
     * @param array<int, array<int, int>>   $sellVolumesByStructureThenTypeId
     */
    public function __construct(
        private readonly array $unitPricesByStructureThenTypeId = [],
        private readonly ?\DateTimeImmutable $lastSync = null,
        private readonly array $sellVolumesByStructureThenTypeId = [],
    ) {
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

    public function sellVolumesFor(int $structureId, array $typeIds): array
    {
        $this->volumeRequests[] = [$structureId, $typeIds];

        $sellVolumes = [];
        foreach ($typeIds as $typeId) {
            if (isset($this->sellVolumesByStructureThenTypeId[$structureId][$typeId])) {
                $sellVolumes[$typeId] = $this->sellVolumesByStructureThenTypeId[$structureId][$typeId];
            }
        }

        return $sellVolumes;
    }
}
