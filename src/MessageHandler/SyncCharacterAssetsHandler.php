<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\EveAuthRequiredException;
use App\Message\SyncCharacterAssets;
use App\Repository\CharacterRepository;
use App\Service\Sync\AssetsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncCharacterAssetsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private AssetsSyncService $assetsSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCharacterAssets $message): void
    {
        $uuid = Uuid::fromString($message->characterId);
        $character = $this->characterRepository->find($uuid);

        if ($character === null) {
            $this->logger->warning('Character not found for sync', ['characterId' => $message->characterId]);
            return;
        }

        if (!$this->assetsSyncService->canSync($character)) {
            $this->logger->info('Character cannot sync', ['characterId' => $message->characterId]);
            return;
        }

        try {
            $this->assetsSyncService->syncCharacterAssets($character);
            $this->logger->info('Character assets synced', [
                'characterId' => $message->characterId,
                'characterName' => $character->getName(),
            ]);
        } catch (EveAuthRequiredException $e) {
            $this->logger->error('EVE auth required for character', [
                'characterId' => $message->characterId,
                'error' => $e->getMessage(),
            ]);
            // Mark user auth as invalid
            $character->getUser()?->markAuthInvalid();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync character assets', [
                'characterId' => $message->characterId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
