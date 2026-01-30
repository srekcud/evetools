<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Message\SyncAnsiblexGates;
use App\Repository\CharacterRepository;
use App\Service\Sync\AnsiblexSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncAnsiblexGatesHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private AnsiblexSyncService $ansiblexSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncAnsiblexGates $message): void
    {
        $characterId = $message->getCharacterId();

        $character = $this->characterRepository->find($characterId);
        if (!$character) {
            $this->logger->warning('Character not found for Ansiblex sync', [
                'character_id' => $characterId,
            ]);
            return;
        }

        if (!$this->ansiblexSyncService->canSync($character)) {
            $this->logger->info('Character cannot sync Ansiblex (missing token or scope)', [
                'character_id' => $characterId,
                'character_name' => $character->getName(),
            ]);
            return;
        }

        try {
            $stats = $this->ansiblexSyncService->syncFromCharacter($character);

            $this->logger->info('Ansiblex sync completed', [
                'character_id' => $characterId,
                'character_name' => $character->getName(),
                'added' => $stats['added'],
                'updated' => $stats['updated'],
                'deactivated' => $stats['deactivated'],
            ]);
        } catch (EveAuthRequiredException $e) {
            $this->logger->warning('EVE auth required for Ansiblex sync', [
                'character_id' => $characterId,
                'character_name' => $character->getName(),
            ]);
        } catch (EsiApiException $e) {
            $this->logger->error('ESI API error during Ansiblex sync', [
                'character_id' => $characterId,
                'character_name' => $character->getName(),
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during Ansiblex sync', [
                'character_id' => $characterId,
                'character_name' => $character->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
