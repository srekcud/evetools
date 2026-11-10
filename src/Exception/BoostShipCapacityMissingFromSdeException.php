<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\MiningBoostShip;

final class BoostShipCapacityMissingFromSdeException extends \RuntimeException
{
    public function __construct(MiningBoostShip $boostShip, int $attributeId)
    {
        parent::__construct(sprintf('Attribute %d of the %s (type %d) is missing from the SDE', $attributeId, $boostShip->name, $boostShip->value));
    }
}
