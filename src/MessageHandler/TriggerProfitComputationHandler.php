<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ComputeProfitMatches;
use App\Message\TriggerProfitComputation;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerProfitComputationHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MessageBusInterface $messageBus,
        private SyncTracker $syncTracker,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerProfitComputation $message): void
    {
        $this->syncTracker->start('profit');

        try {
            $users = $this->userRepository->findActiveWithCharacters();
            $dispatched = 0;

            foreach ($users as $user) {
                $userId = $user->getId()?->toRfc4122();
                if ($userId === null) {
                    continue;
                }

                $this->messageBus->dispatch(new ComputeProfitMatches($userId));
                $dispatched++;

                usleep(100_000); // 100ms between dispatches
            }

            $this->logger->info('Profit computation triggered for active users', [
                'dispatched' => $dispatched,
            ]);

            $this->syncTracker->complete('profit', "{$dispatched} users dispatched");
        } catch (\Throwable $e) {
            $this->syncTracker->fail('profit', $e->getMessage());
            throw $e;
        }
    }
}
