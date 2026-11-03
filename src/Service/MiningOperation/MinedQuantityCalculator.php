<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Enum\MinedQuantityState;

final readonly class MinedQuantityCalculator
{
    public function between(MiningLedgerSnapshot $start, MiningLedgerSnapshot $end, ?int $solarSystemId): MinedQuantity
    {
        return new MinedQuantity(MinedQuantityState::Measuring);
    }

    public function measuredOf(MiningOperationCharacter $operationCharacter): MinedQuantity
    {
        return new MinedQuantity(MinedQuantityState::Measuring);
    }

    public function ofCharacter(MiningOperationCharacter $operationCharacter): MinedQuantity
    {
        return new MinedQuantity(MinedQuantityState::Measuring);
    }

    public function ofParticipant(MiningOperationMember $member): MinedQuantity
    {
        return new MinedQuantity(MinedQuantityState::Measuring);
    }
}
