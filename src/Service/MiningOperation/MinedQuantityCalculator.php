<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningLedgerSnapshotLine;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Enum\MinedQuantityState;
use App\Enum\MinedQuantityUnknownReason;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;

final readonly class MinedQuantityCalculator
{
    public function between(MiningLedgerSnapshot $start, MiningLedgerSnapshot $end, ?int $solarSystemId): MinedQuantity
    {
        $startQuantities = [];
        foreach ($this->linesIn($start, $solarSystemId) as $line) {
            $startQuantities[$this->keyOf($line)] = $line->getQuantity();
        }

        $quantitiesByTypeId = [];
        foreach ($this->linesIn($end, $solarSystemId) as $line) {
            $quantityAtStart = array_key_exists($this->keyOf($line), $startQuantities) ? $startQuantities[$this->keyOf($line)] : 0;
            $mined = $line->getQuantity() - $quantityAtStart;
            if ($mined < 0) {
                return new MinedQuantity(MinedQuantityState::Unknown, MinedQuantityUnknownReason::LedgerInconsistent);
            }
            $this->add($quantitiesByTypeId, $line->getTypeId(), $mined);
        }

        return new MinedQuantity(MinedQuantityState::Known, null, array_filter($quantitiesByTypeId, static fn (int $quantity): bool => $quantity !== 0));
    }

    public function measuredOf(MiningOperationCharacter $operationCharacter): MinedQuantity
    {
        $start = $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::Start);
        if ($start === null || !$start->getStatus()->isSettled()) {
            return new MinedQuantity(MinedQuantityState::Measuring);
        }
        if ($start->getStatus() !== MiningLedgerSnapshotStatus::Taken) {
            return $this->unknownBecauseOf($start->getStatus());
        }

        $end = $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::End);
        if ($end === null || !$end->getStatus()->isSettled()) {
            return new MinedQuantity(MinedQuantityState::Measuring);
        }
        if ($end->getStatus() !== MiningLedgerSnapshotStatus::Taken) {
            return $this->unknownBecauseOf($end->getStatus());
        }

        return $this->between($start, $end, $operationCharacter->getMember()->getOperation()->getSolarSystemId());
    }

    public function ofCharacter(MiningOperationCharacter $operationCharacter): MinedQuantity
    {
        $measured = $this->measuredOf($operationCharacter);
        $corrections = $operationCharacter->latestCorrections();
        if ($corrections === [] || $measured->state === MinedQuantityState::Measuring) {
            return $measured;
        }

        $quantitiesByTypeId = $measured->state === MinedQuantityState::Known ? $measured->quantitiesByTypeId : [];
        foreach ($corrections as $typeId => $correction) {
            $quantitiesByTypeId[$typeId] = $correction->getCorrectedQuantity();
        }

        return new MinedQuantity(MinedQuantityState::Known, null, $quantitiesByTypeId);
    }

    public function ofParticipant(MiningOperationMember $member): MinedQuantity
    {
        $characterQuantities = array_map(
            fn (MiningOperationCharacter $operationCharacter): MinedQuantity => $this->ofCharacter($operationCharacter),
            array_values($member->getCharacters()->toArray()),
        );

        foreach ($characterQuantities as $characterQuantity) {
            if ($characterQuantity->state === MinedQuantityState::Unknown) {
                return new MinedQuantity(MinedQuantityState::Unknown, $characterQuantity->unknownReason);
            }
        }
        foreach ($characterQuantities as $characterQuantity) {
            if ($characterQuantity->state === MinedQuantityState::Measuring) {
                return new MinedQuantity(MinedQuantityState::Measuring);
            }
        }

        $quantitiesByTypeId = [];
        foreach ($characterQuantities as $characterQuantity) {
            foreach ($characterQuantity->quantitiesByTypeId as $typeId => $quantity) {
                $this->add($quantitiesByTypeId, $typeId, $quantity);
            }
        }

        return new MinedQuantity(MinedQuantityState::Known, null, $quantitiesByTypeId);
    }

    /**
     * @param array<int, int> $quantitiesByTypeId
     */
    private function add(array &$quantitiesByTypeId, int $typeId, int $quantity): void
    {
        if (!array_key_exists($typeId, $quantitiesByTypeId)) {
            $quantitiesByTypeId[$typeId] = 0;
        }
        $quantitiesByTypeId[$typeId] += $quantity;
    }

    /**
     * @return list<MiningLedgerSnapshotLine>
     */
    private function linesIn(MiningLedgerSnapshot $snapshot, ?int $solarSystemId): array
    {
        return array_values($snapshot->getLines()->filter(
            static fn (MiningLedgerSnapshotLine $line): bool => $solarSystemId === null || $line->getSolarSystemId() === $solarSystemId,
        )->toArray());
    }

    private function keyOf(MiningLedgerSnapshotLine $line): string
    {
        return sprintf('%s|%d|%d', $line->getDate()->format('Y-m-d'), $line->getTypeId(), $line->getSolarSystemId());
    }

    private function unknownBecauseOf(MiningLedgerSnapshotStatus $status): MinedQuantity
    {
        return new MinedQuantity(MinedQuantityState::Unknown, match ($status) {
            MiningLedgerSnapshotStatus::ReconnectRequired => MinedQuantityUnknownReason::ReconnectRequired,
            MiningLedgerSnapshotStatus::Failed => MinedQuantityUnknownReason::ReadFailed,
            MiningLedgerSnapshotStatus::OutOfWindow => MinedQuantityUnknownReason::OutOfWindow,
            MiningLedgerSnapshotStatus::Scheduling, MiningLedgerSnapshotStatus::Waiting, MiningLedgerSnapshotStatus::Taken => throw new \LogicException(sprintf('A %s snapshot has no unknown reason', $status->value)),
        });
    }
}
