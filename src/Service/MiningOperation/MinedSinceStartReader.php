<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Repository\MiningEntryRepository;

final readonly class MinedSinceStartReader
{
    public function __construct(
        private MiningEntryRepository $entryRepository,
    ) {
    }

    public function ofEnrolment(MiningOperation $operation, MiningOperationCharacter $enrolment): ?MinedSinceStart
    {
        $start = $enrolment->snapshotOf(MiningLedgerSnapshotKind::Start);
        if (null === $start || MiningLedgerSnapshotStatus::Taken !== $start->getStatus()) {
            return null;
        }

        $solarSystemId = $operation->getSolarSystemId();
        $startedAt = $operation->getStartedAt() ?? throw new \LogicException('A running operation has a start date');
        $startDay = $startedAt->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);

        $quantitiesAtStart = [];
        foreach ($start->getLines() as $line) {
            if (null === $solarSystemId || $line->getSolarSystemId() === $solarSystemId) {
                $quantitiesAtStart[$this->keyOf($line->getDate(), $line->getTypeId(), $line->getSolarSystemId())] = $line->getQuantity();
            }
        }

        $minedByTypeId = [];
        $lastChangeAt = null;
        foreach ($this->entryRepository->findOfCharacterFromDay($enrolment->getCharacter()->getEveCharacterId(), $startDay, $solarSystemId) as $entry) {
            $mined = $entry->getQuantity() - ($quantitiesAtStart[$this->keyOf($entry->getDate(), $entry->getTypeId(), $entry->getSolarSystemId())] ?? 0);
            if ($mined < 0) {
                return null;
            }
            $minedByTypeId[$entry->getTypeId()] = ($minedByTypeId[$entry->getTypeId()] ?? 0) + $mined;
            if ($mined > 0 && (null === $lastChangeAt || $entry->getSyncedAt() > $lastChangeAt)) {
                $lastChangeAt = $entry->getSyncedAt();
            }
        }

        return new MinedSinceStart($minedByTypeId, $lastChangeAt);
    }

    private function keyOf(\DateTimeImmutable $day, int $typeId, int $solarSystemId): string
    {
        return \sprintf('%s|%d|%d', $day->format('Y-m-d'), $typeId, $solarSystemId);
    }
}
