<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;
use App\Service\TypeNameResolver;

class BlueprintLibraryReader
{
    public function __construct(
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    public function readFor(User $user): BlueprintLibrary
    {
        return new BlueprintLibrary('unknown', []);
    }
}
