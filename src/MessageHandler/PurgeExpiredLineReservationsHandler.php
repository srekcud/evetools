<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeExpiredLineReservations;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Industry\Project\PilotName;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeExpiredLineReservationsHandler
{
    private const string SYNC_KEY = 'line-reservations-purge';

    public function __construct(
        private IndustryProjectLineReservationRepository $reservationRepository,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(PurgeExpiredLineReservations $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);

        try {
            $expiredReservations = $this->reservationRepository->findExpired();
            $releases = [];
            foreach ($expiredReservations as $reservation) {
                $line = $reservation->getBomLine();
                $releases[] = [
                    'projectId' => $line->getProject()->getId()->toRfc4122(),
                    'data' => [
                        'bomItemId' => $line->getId()->toRfc4122(),
                        'bomItemName' => $line->getTypeName(),
                        'characterName' => PilotName::of($reservation->getParticipant()->getUser()),
                        'quantity' => 0,
                        'released' => true,
                    ],
                ];
                $this->entityManager->remove($reservation);
            }
            $this->entityManager->flush();
        } catch (\Throwable $failure) {
            $this->syncTracker->fail(self::SYNC_KEY, $failure->getMessage());

            throw $failure;
        }

        foreach ($releases as $release) {
            $this->mercurePublisher->publishGroupProjectEvent($release['projectId'], 'line_reserved', $release['data']);
        }

        $deleted = count($releases);
        $this->logger->info('Expired line reservations purge completed', ['deleted' => $deleted]);
        $this->syncTracker->complete(self::SYNC_KEY, sprintf('%d reservation(s) deleted', $deleted));
    }
}
