<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterAssets;
use App\Message\TriggerAssetsSync;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
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
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerAssetsSync $message): void
    {
        $this->syncTracker->start('assets');
        $this->logger->info('Triggering scheduled assets sync');

        try {
            $characters = $this->characterRepository->findWithValidTokens();
            $syncCount = 0;

            foreach ($characters as $character) {
                $characterId = $character->getId()?->toRfc4122();
                if ($characterId !== null && $this->assetsSyncService->shouldSync($character) && $this->assetsSyncService->canSync($character)) {
                    $this->messageBus->dispatch(
                        new SyncCharacterAssets($characterId)
                    );
                    $syncCount++;
                }
            }

            $this->logger->info('Scheduled sync triggered', [
                'charactersQueued' => $syncCount,
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('assets', "{$syncCount}/" . count($characters) . ' chars queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('assets', $e->getMessage());
            throw $e;
        }
    }
}
