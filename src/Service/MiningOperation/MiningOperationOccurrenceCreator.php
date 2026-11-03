<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationRecurrence;
use App\Enum\MiningOperationRecurrenceKind;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MiningOperationRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MiningOperationOccurrenceCreator
{
    private const string ONE_DAY = '+1 day';

    public function __construct(
        private MiningOperationEnrolment $enrolment,
        private MiningOperationRecurrenceSchedule $schedule,
        private MiningOperationRepository $operationRepository,
        private CorporationMiningExtractionRepository $extractionRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<MiningOperation>
     */
    public function createDueOccurrences(MiningOperationRecurrence $recurrence, \DateTimeImmutable $now): array
    {
        if (!$recurrence->isActive()) {
            return [];
        }

        if ($recurrence->getOwner()->getCorporationId() !== $recurrence->getCorporationId()) {
            $recurrence->stop($now);
            $this->entityManager->flush();

            return [];
        }

        return match ($recurrence->getKind()) {
            MiningOperationRecurrenceKind::Weekly => $this->createDueWeeklyOccurrence($recurrence, $now),
            MiningOperationRecurrenceKind::Chunk => $this->createChunkOccurrences($recurrence, $now),
        };
    }

    public function nextWeeklyStartAtOf(MiningOperationRecurrence $recurrence, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $lastPlannedStartAt = $this->operationRepository->findLatestOf($recurrence)?->getPlannedStartAt()
            ?? $recurrence->getAnchorAt()
            ?? throw new \LogicException('A weekly recurrence without operation needs an anchor date');

        return $this->schedule->nextWeeklyStartAt($lastPlannedStartAt, $now);
    }

    /**
     * @return list<MiningOperation>
     */
    private function createDueWeeklyOccurrence(MiningOperationRecurrence $recurrence, \DateTimeImmutable $now): array
    {
        $nextStartAt = $this->nextWeeklyStartAtOf($recurrence, $now);
        if (!$this->schedule->isDue($nextStartAt, $now)) {
            return [];
        }

        return [$this->createOccurrence($recurrence, $nextStartAt, $this->chunkArrivingOnTheDayOf($recurrence, $nextStartAt))];
    }

    /**
     * @return list<MiningOperation>
     */
    private function createChunkOccurrences(MiningOperationRecurrence $recurrence, \DateTimeImmutable $now): array
    {
        $corporationId = $recurrence->getCorporationId();
        $structureId = $recurrence->getStructureId();
        if ($corporationId === null || $structureId === null) {
            throw new \LogicException('A chunk recurrence needs a corporation and a refinery');
        }

        return array_map(
            fn (CorporationMiningExtraction $extraction): MiningOperation => $this->createOccurrence($recurrence, $extraction->getChunkArrivalTime(), $extraction),
            $this->extractionRepository->findWithoutOccurrenceOf($recurrence, $corporationId, $structureId, $now),
        );
    }

    private function chunkArrivingOnTheDayOf(MiningOperationRecurrence $recurrence, \DateTimeImmutable $startAt): ?CorporationMiningExtraction
    {
        $corporationId = $recurrence->getCorporationId();
        if ($corporationId === null) {
            return null;
        }

        $dayStart = $startAt->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $chunks = $this->extractionRepository->findArrivingBetween($corporationId, $dayStart, $dayStart->modify(self::ONE_DAY));

        return count($chunks) === 1 ? $chunks[0] : null;
    }

    private function createOccurrence(MiningOperationRecurrence $recurrence, \DateTimeImmutable $startAt, ?CorporationMiningExtraction $chunk): MiningOperation
    {
        $owner = $recurrence->getOwner();
        $latest = $this->operationRepository->findLatestOf($recurrence);

        $occurrence = $this->enrolment->plan(
            $owner,
            $recurrence->getLabel(),
            $startAt->setTimezone(new \DateTimeZone('UTC')),
            $recurrence->getVisibility(),
            $recurrence->getSplitMode(),
            $recurrence->getCorporationId(),
            $this->enrolment->eligibleCharactersOf($owner),
        )
            ->setRecurrence($recurrence)
            ->setChunk($chunk);

        if ($latest !== null) {
            $this->copyValuationOf($latest, $occurrence);
        }

        $this->entityManager->persist($occurrence);
        $this->entityManager->flush();

        return $occurrence;
    }

    private function copyValuationOf(MiningOperation $source, MiningOperation $occurrence): void
    {
        $owner = $occurrence->getOwner();
        $sourceBooster = $source->getBoosterMember();
        $ownerWasBooster = $sourceBooster !== null
            && PersistedUuid::of($sourceBooster->getUser()->getId()) === PersistedUuid::of($owner->getId());

        $occurrence->updateValuationSettings(
            $source->getValuationForm(),
            $source->getValuationMarket(),
            $source->getValuationSide(),
            $source->getValuationStructureId(),
            $source->getValuationStructureName(),
            $source->getReprocessYield(),
            $source->getBoosterCutPercent(),
            $source->getCorporationCutPercent(),
            $ownerWasBooster ? $occurrence->membershipOf($owner) : null,
        );
    }
}
