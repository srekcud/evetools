<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Enum\MinedQuantityState;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningShareBlocker;
use App\Enum\MiningSplitMode;
use Psr\Clock\ClockInterface;

/**
 * @phpstan-type ParticipantEntry array{member: ?MiningOperationMember, name: ?string, quantity: ?MinedQuantity, minedValue: ?int, keeping: MiningFleetStorageKeeping, characters: list<MiningCharacterValuation>}
 */
final readonly class MiningOperationValuator
{
    private const int SECONDS_PER_HOUR = 3600;
    private const float PERCENT = 100.0;

    public function __construct(
        private MinedQuantityCalculator $quantityCalculator,
        private MiningOperationUnitPrices $unitPrices,
        private MiningShareCalculator $shareCalculator,
        private ClockInterface $clock,
        private MiningFleetStorageCalculator $fleetStorageCalculator = new MiningFleetStorageCalculator(),
    ) {
    }

    public function value(MiningOperation $operation): MiningOperationValuation
    {
        $sharesFrozenAt = $operation->getSharesFrozenAt();

        return $sharesFrozenAt === null
            ? $this->previewOf($operation)
            : $this->frozenOf($operation, $sharesFrozenAt);
    }

    private function previewOf(MiningOperation $operation): MiningOperationValuation
    {
        $members = $operation->participantsInLedgerOrder();
        $reading = $this->unitPrices->read($operation, $this->minedTypeIdsOf($members));

        $participants = array_map(
            fn (MiningOperationMember $member): array => $this->participantOf($member, $this->nameOf($member), $reading->unitPricesByTypeId, null),
            $members,
        );
        $missingPriceTypeIds = array_keys(array_filter($reading->unitPricesByTypeId, static fn (?float $unitPrice): bool => $unitPrice === null));

        return $this->assemble($operation, false, $this->clock->now(), $reading->pricesSyncedAt, $reading->unitPricesByTypeId, $missingPriceTypeIds, $participants);
    }

    private function frozenOf(MiningOperation $operation, \DateTimeImmutable $sharesFrozenAt): MiningOperationValuation
    {
        $unitPrices = [];
        foreach ($operation->getFrozenUnitPrices() as $frozenUnitPrice) {
            $unitPrices[$frozenUnitPrice->getTypeId()] = $frozenUnitPrice->getUnitPrice();
        }
        ksort($unitPrices);

        $frozenMinedValues = array_values($operation->getFrozenMinedValues()->toArray());
        usort($frozenMinedValues, static fn (MiningFrozenMinedValue $left, MiningFrozenMinedValue $right): int => $left->getPosition() <=> $right->getPosition());

        $participants = array_map(
            fn (MiningFrozenMinedValue $frozenMinedValue): array => $this->participantOf(
                $frozenMinedValue->getMember(),
                $frozenMinedValue->getParticipantName(),
                $unitPrices,
                $frozenMinedValue,
            ),
            $frozenMinedValues,
        );

        return $this->assemble($operation, true, $sharesFrozenAt, $operation->getPricesSyncedAt(), $unitPrices, [], $participants);
    }

    /**
     * @param list<MiningOperationMember> $members
     *
     * @return list<int>
     */
    private function minedTypeIdsOf(array $members): array
    {
        $typeIds = [];
        foreach ($members as $member) {
            foreach ($member->getCharacters() as $operationCharacter) {
                foreach ($this->quantityCalculator->ofCharacter($operationCharacter)->quantitiesByTypeId as $typeId => $quantity) {
                    if ($quantity > 0) {
                        $typeIds[$typeId] = $typeId;
                    }
                }
            }
        }
        sort($typeIds);

        return $typeIds;
    }

    /**
     * @param array<int, ?float> $unitPrices
     *
     * @return ParticipantEntry
     */
    private function participantOf(?MiningOperationMember $member, ?string $name, array $unitPrices, ?MiningFrozenMinedValue $frozen): array
    {
        $frozenMinedValue = $frozen === null ? null : (int) $frozen->getMinedValue();
        if ($member === null) {
            return ['member' => null, 'name' => $name, 'quantity' => null, 'minedValue' => $frozenMinedValue, 'keeping' => $this->frozenKeepingOf($frozen), 'characters' => []];
        }

        $quantity = $this->quantityCalculator->ofParticipant($member);
        $minedValue = $frozenMinedValue ?? $this->minedValueOf($quantity, $unitPrices);

        return [
            'member' => $member,
            'name' => $name,
            'quantity' => $quantity,
            'minedValue' => $minedValue,
            'keeping' => $frozen === null
                ? MiningFleetStorageKeeping::of($quantity, $minedValue, $member->getFleetStorageDelivery(), $member->getFleetStorageQuantities(), $unitPrices)
                : $this->frozenKeepingOf($frozen),
            'characters' => $this->charactersOf($member, $unitPrices),
        ];
    }

    private function frozenKeepingOf(?MiningFrozenMinedValue $frozen): MiningFleetStorageKeeping
    {
        $frozen ??= throw new \LogicException('A participant without a member has frozen values');
        $deliveredValue = (int) $frozen->getDeliveredValue();

        return new MiningFleetStorageKeeping((int) $frozen->getMinedValue() - $deliveredValue, $deliveredValue);
    }

    /**
     * @param array<int, ?float> $unitPrices
     *
     * @return list<MiningCharacterValuation>
     */
    private function charactersOf(MiningOperationMember $member, array $unitPrices): array
    {
        $operationCharacters = array_values($member->getCharacters()->toArray());
        usort($operationCharacters, static fn (MiningOperationCharacter $left, MiningOperationCharacter $right): int => $left->getCharacter()->getName() <=> $right->getCharacter()->getName());

        return array_map(function (MiningOperationCharacter $operationCharacter) use ($unitPrices): MiningCharacterValuation {
            $quantity = $this->quantityCalculator->ofCharacter($operationCharacter);

            return new MiningCharacterValuation($operationCharacter, $quantity, $this->minedValueOf($quantity, $unitPrices));
        }, $operationCharacters);
    }

    /**
     * @param array<int, ?float> $unitPrices
     */
    private function minedValueOf(MinedQuantity $quantity, array $unitPrices): ?int
    {
        if ($quantity->state !== MinedQuantityState::Known) {
            return null;
        }

        $value = 0.0;
        foreach ($quantity->quantitiesByTypeId as $typeId => $typeQuantity) {
            if ($typeQuantity === 0) {
                continue;
            }
            $unitPrice = $unitPrices[$typeId] ?? null;
            if ($unitPrice === null) {
                return null;
            }
            $value += $typeQuantity * $unitPrice;
        }

        return (int) round($value);
    }

    /**
     * @param array<int, ?float>       $unitPrices
     * @param list<int>                $missingPriceTypeIds
     * @param list<ParticipantEntry>   $participants
     */
    private function assemble(
        MiningOperation $operation,
        bool $frozen,
        \DateTimeImmutable $pricesReadAt,
        ?\DateTimeImmutable $pricesSyncedAt,
        array $unitPrices,
        array $missingPriceTypeIds,
        array $participants,
    ): MiningOperationValuation {
        $eachForThemselves = $operation->getSplitMode() === MiningSplitMode::EachForThemselves;
        $split = $this->shareCalculator->split(
            array_map(static fn (array $participant): ?int => $participant['minedValue'], $participants),
            $operation->getBoosterCutPercent(),
            $operation->getCorporationCutPercent(),
        );
        $totalValue = $split->totalValue;

        $debts = $eachForThemselves ? null : $this->fleetStorageDebtsOf($operation, $participants, $split);
        $recipient = $operation->getFleetStorageRecipient();

        $valuedParticipants = [];
        foreach ($participants as $index => $participant) {
            $minedValue = $participant['minedValue'];
            $durationSeconds = $participant['member'] === null ? null : $this->durationSecondsOf($operation, $participant['member']);
            $valuedParticipants[] = new MiningParticipantValuation(
                $participant['member'],
                $participant['name'],
                $participant['quantity'],
                $minedValue,
                $eachForThemselves || $minedValue === null || $totalValue === null || $totalValue === 0 ? null : $minedValue / $totalValue * self::PERCENT,
                $eachForThemselves ? null : $split->shares[$index],
                $eachForThemselves ? null : $split->roundingAdjustments[$index],
                $durationSeconds,
                $this->iskPerHourOf($minedValue, $durationSeconds),
                $participant['characters'],
                $participant['member']?->getFleetStorageDelivery(),
                $participant['member'] !== null && $participant['member'] === $recipient,
                $participant['keeping']->deliveredValue,
                $participant['keeping']->keptValue,
                $debts[$index] ?? null,
            );
        }

        $completionBlockers = null;
        if ($eachForThemselves) {
            $completionBlockers = $frozen ? [] : $this->completionBlockersOf($operation, $valuedParticipants, $missingPriceTypeIds);
        }

        $operationDurationSeconds = $this->operationDurationSecondsOf($operation);

        return new MiningOperationValuation(
            $frozen,
            $pricesReadAt,
            $pricesSyncedAt,
            $unitPrices,
            $missingPriceTypeIds,
            $totalValue,
            $eachForThemselves ? null : $split,
            $operationDurationSeconds,
            $this->iskPerHourOf($totalValue, $operationDurationSeconds),
            $valuedParticipants,
            $frozen ? [] : $this->freezeBlockersOf($operation, $valuedParticipants, $missingPriceTypeIds),
            $completionBlockers,
        );
    }

    /**
     * @param list<ParticipantEntry> $participants
     *
     * @return list<int>|null
     */
    private function fleetStorageDebtsOf(MiningOperation $operation, array $participants, MiningShareSplit $split): ?array
    {
        $minedValues = [];
        $keptValues = [];
        $shares = [];
        $recipientPosition = null;
        $deliversSomething = false;
        foreach ($participants as $position => $participant) {
            $minedValue = $participant['minedValue'];
            $keptValue = $participant['keeping']->keptValue;
            $share = $split->shares[$position];
            if ($minedValue === null || $keptValue === null || $share === null) {
                return null;
            }

            $minedValues[] = $minedValue;
            $keptValues[] = $keptValue;
            $shares[] = $share;
            $deliversSomething = $deliversSomething || $keptValue < $minedValue;
            if ($participant['member'] !== null && $participant['member'] === $operation->getFleetStorageRecipient()) {
                $recipientPosition = $position;
            }
        }

        if ($recipientPosition === null && $deliversSomething) {
            return null;
        }

        return $this->fleetStorageCalculator->settle($minedValues, $keptValues, $recipientPosition, $shares)->debts;
    }

    /**
     * @param list<MiningParticipantValuation> $participants
     * @param list<int>                        $missingPriceTypeIds
     *
     * @return list<MiningShareBlocker>
     */
    private function freezeBlockersOf(MiningOperation $operation, array $participants, array $missingPriceTypeIds): array
    {
        $blockers = [];
        if ($operation->getStatus() !== MiningOperationStatus::Closing) {
            $blockers[] = MiningShareBlocker::NotClosing;
        }
        if ($operation->getSplitMode() === MiningSplitMode::EachForThemselves) {
            $blockers[] = MiningShareBlocker::EachForThemselves;

            return $blockers;
        }
        if ($this->hasUnknownQuantity($participants)) {
            $blockers[] = MiningShareBlocker::QuantitiesNotKnown;
        }
        if ($missingPriceTypeIds !== []) {
            $blockers[] = MiningShareBlocker::PricesMissing;
        }
        $booster = $operation->getBoosterMember();
        if ($operation->getBoosterCutPercent() > 0 && ($booster === null || $booster->getStatus() !== MiningOperationMemberStatus::Accepted)) {
            $blockers[] = MiningShareBlocker::BoosterMissing;
        }
        $recipient = $operation->getFleetStorageRecipient();
        if ($this->hasDelivery($participants) && ($recipient === null || $recipient->getStatus() !== MiningOperationMemberStatus::Accepted)) {
            $blockers[] = MiningShareBlocker::FleetStorageRecipientMissing;
        }
        if (array_any($participants, static fn (MiningParticipantValuation $participant): bool => $participant->deliversMoreThanMined())) {
            $blockers[] = MiningShareBlocker::FleetStorageExceedsMined;
        }

        return $blockers;
    }

    /**
     * @param list<MiningParticipantValuation> $participants
     * @param list<int>                        $missingPriceTypeIds
     *
     * @return list<MiningShareBlocker>
     */
    private function completionBlockersOf(MiningOperation $operation, array $participants, array $missingPriceTypeIds): array
    {
        $blockers = [];
        if ($operation->getStatus() !== MiningOperationStatus::Closing) {
            $blockers[] = MiningShareBlocker::NotClosing;
        }
        if ($this->hasUnknownQuantity($participants)) {
            $blockers[] = MiningShareBlocker::QuantitiesNotKnown;
        } elseif ($missingPriceTypeIds !== []) {
            $blockers[] = MiningShareBlocker::PricesMissing;
        }

        return $blockers;
    }

    /**
     * @param list<MiningParticipantValuation> $participants
     */
    private function hasUnknownQuantity(array $participants): bool
    {
        foreach ($participants as $participant) {
            if ($participant->quantity?->state !== MinedQuantityState::Known) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MiningParticipantValuation> $participants
     */
    private function hasDelivery(array $participants): bool
    {
        return array_any($participants, static fn (MiningParticipantValuation $participant): bool => $participant->member !== null && $participant->member->getFleetStorageDelivery() !== MiningFleetStorageDelivery::None);
    }

    private function durationSecondsOf(MiningOperation $operation, MiningOperationMember $member): ?int
    {
        $startedAt = null;
        foreach ($member->getCharacters() as $operationCharacter) {
            $start = $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::Start);
            if ($start?->getStatus() === MiningLedgerSnapshotStatus::Taken && ($startedAt === null || $start->getReferenceAt() < $startedAt)) {
                $startedAt = $start->getReferenceAt();
            }
        }

        return $this->secondsBetween($startedAt, $member->getDepartedAt() ?? $operation->getEndedAt());
    }

    private function operationDurationSecondsOf(MiningOperation $operation): ?int
    {
        return $this->secondsBetween($operation->getStartedAt(), $operation->getEndedAt());
    }

    private function secondsBetween(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): ?int
    {
        return $from === null || $to === null ? null : $to->getTimestamp() - $from->getTimestamp();
    }

    private function iskPerHourOf(?int $value, ?int $durationSeconds): ?float
    {
        if ($value === null || $durationSeconds === null || $durationSeconds <= 0) {
            return null;
        }

        return $value / $durationSeconds * self::SECONDS_PER_HOUR;
    }

    private function nameOf(MiningOperationMember $member): ?string
    {
        return $member->getUser()->getMainCharacter()?->getName();
    }
}
