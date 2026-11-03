<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;

final class MiningOperationAudience
{
    /**
     * @return list<string> ids of the users to notify: owner, pending and accepted members, plus the given users
     */
    public static function userIdsOf(MiningOperation $operation, User ...$additionalUsers): array
    {
        return array_values(array_unique(array_map(
            static fn (User $user): string => PersistedUuid::of($user->getId()),
            [...$operation->notifiedUsers(), ...$additionalUsers],
        )));
    }
}
