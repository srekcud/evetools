<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCorporationAssets;
use App\Message\TriggerCorporationAssetsSync;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Sync\AssetsSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerCorporationAssetsSyncHandler
{
    public function __construct(
        private CorpAssetVisibilityRepository $visibilityRepository,
        private AssetsSyncService $assetsSyncService,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(TriggerCorporationAssetsSync $message): void
    {
        foreach ($this->visibilityRepository->findAll() as $visibility) {
            $corporationId = $visibility->getCorporationId();
            $character = $this->assetsSyncService->getCorpAssetsCharacter($corporationId);
            $characterId = $character?->getId()?->toRfc4122();
            $ownerId = $character?->getUser()?->getId()?->toRfc4122();

            if ($characterId === null || $ownerId === null) {
                continue;
            }

            $this->messageBus->dispatch(new SyncCorporationAssets($corporationId, $characterId, $ownerId));
        }
    }
}
