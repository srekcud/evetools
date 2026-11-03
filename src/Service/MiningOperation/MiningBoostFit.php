<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;

final readonly class MiningBoostFit
{
    public function __construct(private MountableCompressorCatalog $catalog)
    {
    }

    /**
     * @param list<MiningCompressorKind> $kinds
     */
    public function assertMountable(MiningBoostShip $ship, array $kinds): void
    {
        throw new \LogicException('not implemented');
    }
}
