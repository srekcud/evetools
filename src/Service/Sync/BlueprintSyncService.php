<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;
use App\Service\ESI\BlueprintsService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BlueprintSyncService
{
    public function __construct(
        private readonly BlueprintsService $blueprintsService,
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncUserBlueprints(User $user): void
    {
    }
}
