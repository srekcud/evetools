<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterIndustryJobs;
use App\Message\SyncIndustryJobs;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SyncIndustryJobsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncIndustryJobs $message): void
    {
        $this->syncTracker->start('industry');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $queued = 0;

            foreach ($characters as $character) {
                $characterId = $character->getId()?->toRfc4122();
                $token = $character->getEveToken();
                if ($characterId === null || $token === null || !$token->hasScope('esi-industry.read_character_jobs.v1')) {
                    continue;
                }

                $this->messageBus->dispatch(new SyncCharacterIndustryJobs($characterId));
                $queued++;
            }

            $this->logger->info('Industry jobs sync queued', [
                'charactersQueued' => $queued,
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('industry', "{$queued}/" . count($characters) . ' chars queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('industry', $e->getMessage());
            throw $e;
        }
    }
}
