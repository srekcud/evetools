<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Exception\BoostShipCapacityMissingFromSdeException;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;

final readonly class MiningBoostShipCapacities
{
    private const int FLEET_HANGAR_CAPACITY_ATTRIBUTE_ID = 912;
    private const int GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE_ID = 1556;

    public function __construct(private DogmaAttributeValues $attributeValues)
    {
    }

    public function of(MiningBoostShip $boostShip): MiningBoostShipCapacity
    {
        $values = $this->attributeValues->of($boostShip->value, [self::FLEET_HANGAR_CAPACITY_ATTRIBUTE_ID, self::GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE_ID]);

        return new MiningBoostShipCapacity(
            $values[self::FLEET_HANGAR_CAPACITY_ATTRIBUTE_ID] ?? throw new BoostShipCapacityMissingFromSdeException($boostShip, self::FLEET_HANGAR_CAPACITY_ATTRIBUTE_ID),
            $values[self::GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE_ID] ?? throw new BoostShipCapacityMissingFromSdeException($boostShip, self::GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE_ID),
        );
    }
}
