<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\EveAuthRequiredException;
use App\Message\SyncCorporationAssets;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AssetsSyncService;
use App\Service\Sync\CorporationSyncRecipients;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncCorporationAssetsHandler
{
    private const string TRACKED_SYNC_TYPE = 'assets';
    private const string MERCURE_SYNC_TYPE = 'corporation-assets';
    private const string TRIGGER_CHARACTER_NOT_FOUND = 'Corporation assets sync could not run: the trigger character no longer exists';
    private const string AUTH_REQUIRED = 'Corporation assets sync could not run: the trigger character must log in again';

    public function __construct(
        private CharacterRepository $characterRepository,
        private AssetsSyncService $assetsSyncService,
        private MercurePublisherService $mercurePublisher,
        private SyncTracker $syncTracker,
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
            $this->syncTracker->fail(self::TRACKED_SYNC_TYPE, self::TRIGGER_CHARACTER_NOT_FOUND);

            return;
        }

        if (!$this->assetsSyncService->canSync($character)) {
            $this->logger->info('Character cannot sync corporation', [
                'characterId' => $message->triggerCharacterId,
                'corporationId' => $message->corporationId,
            ]);
            foreach (CorporationSyncRecipients::of($character, $message->requestedByUserId) as $userId) {
                $this->mercurePublisher->syncError($userId, self::MERCURE_SYNC_TYPE, self::AUTH_REQUIRED);
            }
            $this->syncTracker->fail(self::TRACKED_SYNC_TYPE, self::AUTH_REQUIRED);

            return;
        }

        try {
            $this->assetsSyncService->syncCorporationAssets($character, $message->requestedByUserId);
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
            $this->syncTracker->fail(self::TRACKED_SYNC_TYPE, self::AUTH_REQUIRED);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync corporation assets', [
                'corporationId' => $message->corporationId,
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail(self::TRACKED_SYNC_TYPE, $e->getMessage());

            throw $e;
        }
    }
}
