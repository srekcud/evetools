<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncAnsiblexGates;
use App\Message\TriggerAnsiblexSync;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\AnsiblexSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerAnsiblexSyncHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private AnsiblexSyncService $ansiblexSyncService,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerAnsiblexSync $message): void
    {
        $this->syncTracker->start('ansiblex');
        $this->logger->info('Triggering scheduled Ansiblex sync');

        try {
            $users = $this->userRepository->findActiveWithCharacters();
            $dispatched = 0;

            foreach ($users as $user) {
                $mainCharacter = $user->getMainCharacter();
                if ($mainCharacter === null) {
                    continue;
                }

                if (!$this->ansiblexSyncService->canSync($mainCharacter) || !$this->ansiblexSyncService->shouldSync($mainCharacter)) {
                    continue;
                }

                $this->messageBus->dispatch(new SyncAnsiblexGates($mainCharacter->getId()->toRfc4122()));
                $dispatched++;

                $this->logger->debug('Dispatched Ansiblex sync for character', [
                    'character_id' => $mainCharacter->getId()->toRfc4122(),
                    'character_name' => $mainCharacter->getName(),
                ]);
            }

            $this->logger->info('Ansiblex sync trigger completed', [
                'dispatched' => $dispatched,
            ]);

            $this->syncTracker->complete('ansiblex', "{$dispatched} chars dispatched");
        } catch (\Throwable $e) {
            $this->syncTracker->fail('ansiblex', $e->getMessage());
            throw $e;
        }
    }
}
