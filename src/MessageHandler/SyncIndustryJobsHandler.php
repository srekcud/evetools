<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncIndustryJobs;
use App\Message\SyncUserIndustryJobs;
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
            $userIds = [];

            foreach ($characters as $character) {
                $userId = $character->getUser()?->getId()?->toRfc4122();
                if ($userId === null || isset($userIds[$userId])) {
                    continue;
                }

                $userIds[$userId] = true;
                $this->messageBus->dispatch(new SyncUserIndustryJobs($userId));
            }

            $this->logger->info('Industry jobs sync queued', [
                'usersQueued' => count($userIds),
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('industry', count($userIds) . ' users queued for ' . count($characters) . ' chars');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('industry', $e->getMessage());
            throw $e;
        }
    }
}
