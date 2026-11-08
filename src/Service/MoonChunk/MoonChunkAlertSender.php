<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonChunkAlert;
use App\Entity\MoonRefineryFollow;
use App\Entity\Notification;
use App\Enum\MoonChunkAlertKind;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonChunkAlertRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class MoonChunkAlertSender
{
    private const string SOON_LEAD = '1 hour';
    private const string ALERTS_ROUTE = '/ledger?tab=mining&view=moonChunks';
    private const string EVE_CLOCK_FORMAT = 'H:i';
    private const string SOON_TITLE = 'Moon chunk ready in 1 hour';
    private const string ARRIVED_TITLE = 'Moon chunk arrived';

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
        $now = $this->clock->now();
        $chunksByRefinery = [];

        foreach ($this->followRepository->findAll() as $follow) {
            $refineryKey = $follow->getCorporationId() . ':' . $follow->getStructureId();
            $chunksByRefinery[$refineryKey] ??= $this->extractionRepository->findBy([
                'corporationId' => $follow->getCorporationId(),
                'structureId' => $follow->getStructureId(),
            ]);

            foreach ($chunksByRefinery[$refineryKey] as $chunk) {
                $this->alertFollower($follow, $chunk, $now);
            }
        }
    }

    private function alertFollower(MoonRefineryFollow $follow, CorporationMiningExtraction $chunk, \DateTimeImmutable $now): void
    {
        $user = $follow->getUser();
        if ($user->getCorporationId() !== $chunk->getCorporationId()) {
            return;
        }

        $kind = $this->dueKind($chunk, $now);
        if ($kind === null || $this->instantOf($kind, $chunk) < $follow->getCreatedAt()) {
            return;
        }

        if ($this->alertRepository->findOneBy(['user' => $user, 'extraction' => $chunk, 'kind' => $kind]) !== null) {
            return;
        }

        $extractionId = PersistedUuid::of($chunk->getId());
        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_MOON_CHUNK,
            $kind === MoonChunkAlertKind::Soon ? Notification::LEVEL_INFO : Notification::LEVEL_SUCCESS,
            $kind === MoonChunkAlertKind::Soon ? self::SOON_TITLE : self::ARRIVED_TITLE,
            $this->messageOf($kind, $chunk),
            ['extractionId' => $extractionId, 'structureId' => $chunk->getStructureId(), 'kind' => $kind->value],
            self::ALERTS_ROUTE,
        );

        $this->entityManager->persist((new MoonChunkAlert())->setUser($user)->setExtraction($chunk)->setKind($kind)->setSentAt($now));
        $this->entityManager->flush();
    }

    private function dueKind(CorporationMiningExtraction $chunk, \DateTimeImmutable $now): ?MoonChunkAlertKind
    {
        if ($now >= $chunk->getNaturalDecayTime()) {
            return null;
        }

        if ($now >= $chunk->getChunkArrivalTime()) {
            return MoonChunkAlertKind::Arrived;
        }

        return $now >= $this->instantOf(MoonChunkAlertKind::Soon, $chunk) ? MoonChunkAlertKind::Soon : null;
    }

    private function instantOf(MoonChunkAlertKind $kind, CorporationMiningExtraction $chunk): \DateTimeImmutable
    {
        return match ($kind) {
            MoonChunkAlertKind::Soon => $chunk->getChunkArrivalTime()->modify('-' . self::SOON_LEAD),
            MoonChunkAlertKind::Arrived => $chunk->getChunkArrivalTime(),
        };
    }

    private function messageOf(MoonChunkAlertKind $kind, CorporationMiningExtraction $chunk): string
    {
        $moon = $chunk->getMoonName() ?? (string) $chunk->getMoonId();
        $refinery = $chunk->getStructureName() ?? (string) $chunk->getStructureId();
        $arrival = $chunk->getChunkArrivalTime()->setTimezone(new \DateTimeZone('UTC'))->format(self::EVE_CLOCK_FORMAT);

        return match ($kind) {
            MoonChunkAlertKind::Soon => sprintf('The chunk of %s (%s) arrives at %s EVE time.', $moon, $refinery, $arrival),
            MoonChunkAlertKind::Arrived => sprintf('The chunk of %s (%s) arrived at %s EVE time: it can be mined.', $moon, $refinery, $arrival),
        };
    }
}
