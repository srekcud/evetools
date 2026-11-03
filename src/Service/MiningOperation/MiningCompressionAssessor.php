<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;

final class MiningCompressionAssessor
{
    /**
     * @param list<MiningCompressorKind>        $mountable
     * @param list<MiningCompressorKind>        $mounted
     * @param array<int, ?MiningCompressorKind> $kindByTypeId
     */
    public function assess(?MiningBoostShip $boostShip, array $mountable, array $mounted, array $kindByTypeId): MiningCompressionAssessment
    {
        return new MiningCompressionAssessment([], [], []);
    }
}
