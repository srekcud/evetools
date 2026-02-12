<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncAnsiblexGates;
use App\Message\TriggerAnsiblexSync;
use App\Repository\UserRepository;
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
    ) {
    }

    public function __invoke(TriggerAnsiblexSync $message): void
    {
        $this->logger->info('Triggering scheduled Ansiblex sync');

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
    }
}
