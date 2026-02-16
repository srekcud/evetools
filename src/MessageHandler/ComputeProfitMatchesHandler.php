<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ComputeProfitMatches;
use App\Repository\UserRepository;
use App\Service\Industry\ProfitMatchingService;
use App\Service\Mercure\MercurePublisherService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ComputeProfitMatchesHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ProfitMatchingService $profitMatchingService,
        private MercurePublisherService $mercurePublisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ComputeProfitMatches $message): void
    {
        $userId = $message->userId;

        try {
            $user = $this->userRepository->find($userId);

            if ($user === null) {
                $this->logger->warning('User not found for profit matching', ['userId' => $userId]);
                return;
            }

            $this->mercurePublisher->syncStarted($userId, 'profit-tracker', 'Computing profit matches...');

            $matchCount = $this->profitMatchingService->computeMatches($user, $message->days);

            $this->mercurePublisher->syncCompleted(
                $userId,
                'profit-tracker',
                sprintf('%d matches computed', $matchCount),
                ['matchCount' => $matchCount]
            );

            $this->logger->info('Profit matching completed', [
                'userId' => $userId,
                'matchCount' => $matchCount,
                'days' => $message->days,
            ]);
        } catch (\Throwable $e) {
            $this->mercurePublisher->syncError($userId, 'profit-tracker', $e->getMessage());

            $this->logger->error('Profit matching failed', [
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
