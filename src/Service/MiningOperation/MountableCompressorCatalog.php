<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;

interface MountableCompressorCatalog
{
    /**
     * @return list<MiningCompressorKind>
     */
    public function kindsMountableOn(MiningBoostShip $ship): array;

    /**
     * @return array<string, int>
     */
    public function compressorTypesOf(MiningBoostShip $ship): array;
}
