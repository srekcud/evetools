<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Isk;

interface StructureMarketPrices
{
    public function isSynced(int $structureId): bool;

    public function lastSyncOf(int $structureId): ?\DateTimeImmutable;

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, Isk>
     */
    public function unitPricesFor(int $structureId, array $typeIds): array;

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, int> quantity on sale by typeId; a typeId without a recent snapshot is absent, never 0
     */
    public function sellVolumesFor(int $structureId, array $typeIds): array;
}
