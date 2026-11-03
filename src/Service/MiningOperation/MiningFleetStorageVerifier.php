<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningFleetStorageCheck;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Assets\CorporationContainerContents;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\OreValueService;
use App\Service\TypeNameResolver;

final readonly class MiningFleetStorageVerifier
{
    public function __construct(
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private CachedAssetRepository $cachedAssetRepository,
        private CorpAssetVisibilityRepository $visibilityRepository,
        private OreValueService $oreValueService,
        private MinedQuantityCalculator $quantityCalculator,
        private TypeNameResolver $typeNameResolver,
        private CorporationContainerContents $containerContents = new CorporationContainerContents(),
    ) {
    }

    public function verify(MiningOperation $operation, User $reader): MiningFleetStorageVerification
    {
        return new MiningFleetStorageVerification(MiningFleetStorageCheck::Unknown, null, null, null, []);
    }
}
