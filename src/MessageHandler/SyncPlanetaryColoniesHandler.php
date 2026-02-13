<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncPlanetaryColonies;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\PlanetarySyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncPlanetaryColoniesHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private PlanetarySyncService $planetarySyncService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncPlanetaryColonies $message): void
    {
        $this->syncTracker->start('planetary');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $synced = 0;

            foreach ($characters as $character) {
                $token = $character->getEveToken();
                if ($token === null || !$token->hasScope('esi-planets.manage_planets.v1')) {
                    continue;
                }

                try {
                    $this->planetarySyncService->syncCharacterColonies($character);
                    $synced++;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to sync planetary colonies', [
                        'characterName' => $character->getName(),
                        'error' => $e->getMessage(),
                    ]);
                }

                usleep(500_000);
            }

            $this->logger->info('Planetary colonies sync completed', [
                'synced' => $synced,
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('planetary', "{$synced}/" . count($characters) . ' chars synced');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('planetary', $e->getMessage());
            throw $e;
        }
    }
}
