<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Dto\Transfer\TransferLine;
use App\Entity\IndustryProjectStationTransfer;
use App\Enum\TransferCarrierKind;
use App\Service\Industry\Project\Courier\CourierQuoteCalculator;
use App\Service\Industry\Project\ProjectContainerReading;

final readonly class TransferLegStateCalculator
{
    private const int PILOT_MINUTES = 30;
    private const int JUMP_BRIDGE_MINUTES = 120;
    private const int VOLUME_DECIMALS = 6;
    private const string REASON_PREVIOUS_LEG_UNKNOWN = 'previous_leg_unknown';

    /**
     * @param array<int, ProjectContainerReading> $readingsByLocationId
     * @param array<int, float|null> $volumeByTypeId
     * @param array<int, string> $typeNameByTypeId
     */
    public function compute(
        IndustryProjectStationTransfer $leg,
        array $readingsByLocationId,
        ?TransferLegState $previousLeg,
        array $volumeByTypeId,
        array $typeNameByTypeId,
    ): TransferLegState {
        if ($leg->getLegIndex() > 0 && null === $previousLeg) {
            throw new \LogicException(\sprintf('Leg %d of route %s needs the state of the leg before it.', $leg->getLegIndex(), $leg->getRouteId()->toRfc4122()));
        }

        $departureReading = $this->readingAt($readingsByLocationId, $leg->getFrom()->locationId);
        $arrivalReading = $this->readingAt($readingsByLocationId, $leg->getTo()->locationId);

        $lines = [];
        foreach ($leg->getLines() as $line) {
            $lines[] = $this->lineStateOf($leg, $line, $departureReading, $arrivalReading, $previousLeg, $volumeByTypeId[$line->typeId] ?? null, $typeNameByTypeId[$line->typeId] ?? (string) $line->typeId);
        }

        [$volume, $unknownReason] = $this->volumeOf($lines);

        return new TransferLegState(
            $leg->getId()->toRfc4122(),
            $leg->getRouteId()->toRfc4122(),
            $leg->getLegIndex(),
            $leg->getTo()->locationId,
            $this->stateOf($lines),
            $lines,
            $volume,
            $unknownReason,
            $leg->getDeclaredAt()->modify($this->durationOf($leg->getCarrierKind())),
        );
    }

    private function lineStateOf(
        IndustryProjectStationTransfer $leg,
        TransferLine $line,
        ?ProjectContainerReading $departureReading,
        ?ProjectContainerReading $arrivalReading,
        ?TransferLegState $previousLeg,
        ?float $typeVolume,
        string $typeName,
    ): StationTransferLineState {
        $unknownReason = null;

        if ($leg->getLegIndex() > 0) {
            $previousLine = $this->lineOf($previousLeg, $line->typeId);
            $departed = $previousLine->arrived;
            $departureSource = StationTransferLineState::SOURCE_DECLARED;
            $unknownReason = null === $departed ? ($previousLine->unknownReason ?? self::REASON_PREVIOUS_LEG_UNKNOWN) : null;
        } elseif (null !== $departureReading && null !== $line->departureBaseline) {
            $departureSource = StationTransferLineState::SOURCE_ASSETS;
            if (null === $departureReading->contents) {
                $departed = null;
                $unknownReason = $departureReading->unknownReason;
            } else {
                $departed = $this->clamp($line->departureBaseline - $this->quantityOf($departureReading, $line->typeId), $line->quantity);
            }
        } else {
            $departed = $line->quantity;
            $departureSource = StationTransferLineState::SOURCE_DECLARED;
        }

        if (null !== $leg->getArrivedAt()) {
            $arrived = $line->quantity;
            $arrivalSource = StationTransferLineState::SOURCE_DECLARED;
        } elseif (null !== $arrivalReading && null !== $line->arrivalBaseline) {
            $arrivalSource = StationTransferLineState::SOURCE_ASSETS;
            if (null === $arrivalReading->contents) {
                $arrived = null;
                $unknownReason ??= $arrivalReading->unknownReason;
            } else {
                $arrived = $this->clamp($this->quantityOf($arrivalReading, $line->typeId) - $line->arrivalBaseline, $line->quantity);
            }
        } else {
            $arrived = 0;
            $arrivalSource = StationTransferLineState::SOURCE_PENDING;
        }

        return new StationTransferLineState(
            $line->typeId,
            $typeName,
            $line->quantity,
            null === $typeVolume ? null : round($line->quantity * $typeVolume, self::VOLUME_DECIMALS),
            $departed,
            $arrived,
            $this->inTransitOf($line->quantity, $departed, $arrived),
            $departureSource,
            $arrivalSource,
            $unknownReason,
        );
    }

    private function inTransitOf(int $quantity, ?int $departed, ?int $arrived): ?int
    {
        if ($quantity === $arrived) {
            return 0;
        }

        if (null === $departed || null === $arrived) {
            return null;
        }

        return max(0, $departed - $arrived);
    }

    /**
     * @param list<StationTransferLineState> $lines
     */
    private function stateOf(array $lines): StationTransferState
    {
        $allArrived = true;
        $noneDeparted = true;
        foreach ($lines as $line) {
            if (null === $line->departed || null === $line->arrived || null === $line->inTransit) {
                if ($line->quantity !== $line->arrived) {
                    return StationTransferState::Unknown;
                }
            }
            $allArrived = $allArrived && $line->quantity === $line->arrived;
            $noneDeparted = $noneDeparted && 0 === $line->departed;
        }

        return match (true) {
            $allArrived => StationTransferState::Arrived,
            $noneDeparted => StationTransferState::AwaitingDeparture,
            default => StationTransferState::InTransit,
        };
    }

    /**
     * @param list<StationTransferLineState> $lines
     *
     * @return array{0: float|null, 1: string|null}
     */
    private function volumeOf(array $lines): array
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (null === $line->volume) {
                return [null, TransferLegState::REASON_VOLUME_UNKNOWN];
            }
            $total += $line->volume;
        }

        return [round($total, self::VOLUME_DECIMALS), null];
    }

    private function durationOf(TransferCarrierKind $carrierKind): string
    {
        return match ($carrierKind) {
            TransferCarrierKind::Pilot => \sprintf('+%d minutes', self::PILOT_MINUTES),
            TransferCarrierKind::JumpBridge => \sprintf('+%d minutes', self::JUMP_BRIDGE_MINUTES),
            TransferCarrierKind::Courier => \sprintf('+%d days', CourierQuoteCalculator::DAYS_TO_COMPLETE),
        };
    }

    /**
     * @param array<int, ProjectContainerReading> $readingsByLocationId
     */
    private function readingAt(array $readingsByLocationId, ?int $locationId): ?ProjectContainerReading
    {
        return null === $locationId ? null : ($readingsByLocationId[$locationId] ?? null);
    }

    private function quantityOf(ProjectContainerReading $reading, int $typeId): int
    {
        foreach ($reading->contents ?? [] as $content) {
            if ($content->typeId === $typeId) {
                return $content->quantity;
            }
        }

        return 0;
    }

    private function clamp(int $value, int $maximum): int
    {
        return max(0, min($maximum, $value));
    }

    private function lineOf(?TransferLegState $leg, int $typeId): StationTransferLineState
    {
        if (null === $leg) {
            throw new \LogicException('A later leg needs the state of the leg before it.');
        }

        foreach ($leg->lines as $line) {
            if ($line->typeId === $typeId) {
                return $line;
            }
        }

        throw new \LogicException(\sprintf('The leg before has no line for type %d.', $typeId));
    }
}
