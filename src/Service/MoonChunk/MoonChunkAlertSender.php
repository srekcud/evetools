<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonChunkAlertRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class MoonChunkAlertSender
{
    public function __construct(
        private MoonRefineryFollowRepository $followRepository,
        private CorporationMiningExtractionRepository $extractionRepository,
        private MoonChunkAlertRepository $alertRepository,
        private NotificationDispatcher $dispatcher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function sendDueAlerts(): void
    {
    }
}
