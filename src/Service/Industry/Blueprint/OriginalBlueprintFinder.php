<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

use App\Entity\User;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;

class OriginalBlueprintFinder
{
    public function __construct(
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
    ) {
    }

    /** @return list<int> */
    public function typeIdsOwnedBy(User $user): array
    {
        return [];
    }
}
