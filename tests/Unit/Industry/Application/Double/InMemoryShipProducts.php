<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\ShipProducts;

final readonly class InMemoryShipProducts implements ShipProducts
{
    /**
     * @param list<int> $shipTypeIds
     */
    public function __construct(private array $shipTypeIds = [])
    {
    }

    public function isShip(int $typeId): bool
    {
        return \in_array($typeId, $this->shipTypeIds, true);
    }
}
