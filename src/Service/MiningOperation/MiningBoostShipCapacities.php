<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;

final readonly class MiningBoostShipCapacities
{
    public function __construct(private DogmaAttributeValues $attributeValues)
    {
    }

    public function of(MiningBoostShip $boostShip): MiningBoostShipCapacity
    {
        return new MiningBoostShipCapacity(0.0, 0.0);
    }
}
