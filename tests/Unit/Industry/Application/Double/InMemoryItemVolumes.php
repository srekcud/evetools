<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\ItemVolumes;

final class InMemoryItemVolumes implements ItemVolumes
{
    public array $requestedTypeIds = [];

    public function __construct(private readonly array $cubicMetersByTypeId = [])
    {
    }

    public function volumeOf(int $typeId): ?float
    {
        $this->requestedTypeIds[] = $typeId;

        return $this->cubicMetersByTypeId[$typeId] ?? null;
    }
}
