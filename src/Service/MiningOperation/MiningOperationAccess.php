<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;

final readonly class MiningOperationAccess
{
    public function canRead(User $user, MiningOperation $operation): bool
    {
        return false;
    }

    public function canParticipate(User $user, MiningOperation $operation): bool
    {
        return false;
    }

    public function canAdminister(User $user, MiningOperation $operation): bool
    {
        return false;
    }

    public function assertCanRead(User $user, MiningOperation $operation): void
    {
    }

    public function assertCanParticipate(User $user, MiningOperation $operation): void
    {
    }

    public function assertCanAdminister(User $user, MiningOperation $operation): void
    {
    }

    public function assertCanManageMember(User $user, MiningOperation $operation, MiningOperationMember $member): void
    {
    }
}
