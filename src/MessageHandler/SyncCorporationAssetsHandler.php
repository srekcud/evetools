<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\EveAuthRequiredException;
use App\Message\SyncCorporationAssets;
use App\Repository\CharacterRepository;
use App\Service\Sync\AssetsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncCorporationAssetsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private AssetsSyncService $assetsSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCorporationAssets $message): void
    {
        $uuid = Uuid::fromString($message->triggerCharacterId);
        $character = $this->characterRepository->find($uuid);

        if ($character === null) {
            $this->logger->warning('Trigger character not found for corp sync', [
                'characterId' => $message->triggerCharacterId,
                'corporationId' => $message->corporationId,
            ]);
            return;
        }

        if (!$this->assetsSyncService->canSync($character)) {
            $this->logger->info('Character cannot sync corporation', [
                'characterId' => $message->triggerCharacterId,
                'corporationId' => $message->corporationId,
            ]);
            return;
        }

        try {
            $this->assetsSyncService->syncCorporationAssets($character);
            $this->logger->info('Corporation assets synced', [
                'corporationId' => $message->corporationId,
                'triggeredBy' => $character->getName(),
            ]);
        } catch (EveAuthRequiredException $e) {
            $this->logger->error('EVE auth required for corporation sync', [
                'characterId' => $message->triggerCharacterId,
                'corporationId' => $message->corporationId,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync corporation assets', [
                'corporationId' => $message->corporationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
