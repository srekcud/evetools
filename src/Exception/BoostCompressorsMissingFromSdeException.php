<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\MiningBoostShip;

final class BoostCompressorsMissingFromSdeException extends \RuntimeException
{
    public function __construct(MiningBoostShip $boostShip)
    {
        parent::__construct(sprintf('No compressor of the %s (type %d) in the SDE', $boostShip->name, $boostShip->value));
    }
}
