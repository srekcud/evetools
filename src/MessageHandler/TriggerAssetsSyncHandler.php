<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterAssets;
use App\Message\TriggerAssetsSync;
use App\Repository\CharacterRepository;
use App\Service\Sync\AssetsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerAssetsSyncHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private AssetsSyncService $assetsSyncService,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerAssetsSync $message): void
    {
        $this->logger->info('Triggering scheduled assets sync');

        $characters = $this->characterRepository->findWithValidTokens();
        $syncCount = 0;

        foreach ($characters as $character) {
            // Check if sync is needed AND if the character can sync (valid token/auth)
            if ($this->assetsSyncService->shouldSync($character) && $this->assetsSyncService->canSync($character)) {
                $this->messageBus->dispatch(
                    new SyncCharacterAssets($character->getId()->toRfc4122())
                );
                $syncCount++;
            }
        }

        $this->logger->info('Scheduled sync triggered', [
            'charactersQueued' => $syncCount,
            'totalCharacters' => count($characters),
        ]);
    }
}
