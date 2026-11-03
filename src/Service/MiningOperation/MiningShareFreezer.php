<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningFrozenUnitPrice;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MinedQuantityState;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningPaymentKind;
use App\Enum\MiningShareBlocker;
use App\Enum\MiningSplitMode;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class MiningShareFreezer
{
    use LocksMiningOperation;

    private const string NOT_CLOSING_MESSAGE = 'Only a closing operation can have its shares frozen';
    private const string EACH_FOR_THEMSELVES_MESSAGE = 'An each-for-themselves operation has no shares';
    private const string QUANTITIES_NOT_KNOWN_MESSAGE = 'Every mined quantity must be known before freezing the shares: %s';
    private const string PRICES_MISSING_MESSAGE = 'Some ores have no price on the valuation basis: %s';
    private const string BOOSTER_MISSING_MESSAGE = 'Choose the booster who receives the booster cut';
    private const string FLEET_STORAGE_RECIPIENT_MISSING_MESSAGE = 'Choose the fleet storage recipient';
    private const string FLEET_STORAGE_EXCEEDS_MINED_MESSAGE = 'Some delivered quantities exceed what was mined: %s';
    private const string NOT_CLOSING_FOR_COMPLETION_MESSAGE = 'Only a closing operation can be completed';
    private const string NOT_EACH_FOR_THEMSELVES_MESSAGE = 'A value prorata operation is settled through its shares and payments';
    private const string QUANTITIES_NOT_KNOWN_FOR_COMPLETION_MESSAGE = 'Every mined quantity must be known before completing the operation: %s';

    public function __construct(
        private MiningOperationValuator $valuator,
        private TypeNameResolver $typeNameResolver,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MiningPaymentPlanner $paymentPlanner = new MiningPaymentPlanner(),
        private MiningFleetStorageCalculator $fleetStorageCalculator = new MiningFleetStorageCalculator(),
    ) {
    }

    public function freeze(MiningOperation $operation): void
    {
        $this->underLockOf($operation, function () use ($operation): void {
            if ($operation->getStatus() !== MiningOperationStatus::Closing) {
                throw new BadRequestHttpException(self::NOT_CLOSING_MESSAGE);
            }

            $valuation = $this->valuator->value($operation);
            $blocker = $valuation->freezeBlockers[0] ?? null;
            if ($blocker !== null) {
                throw new BadRequestHttpException($this->refusalOf($blocker, $valuation, self::QUANTITIES_NOT_KNOWN_MESSAGE));
            }

            $minedValues = $this->frozenMinedValuesOf($operation, $valuation);
            $operation->freezeShares($this->clock->now(), $valuation->pricesSyncedAt, $this->frozenUnitPricesOf($operation, $valuation), $minedValues);
            $this->planPaymentsOf($operation, $valuation, $minedValues);
            $this->entityManager->flush();
        });
    }

    public function complete(MiningOperation $operation, User $completedBy): void
    {
        $this->underLockOf($operation, function () use ($operation, $completedBy): void {
            if ($operation->getStatus() !== MiningOperationStatus::Closing) {
                throw new BadRequestHttpException(self::NOT_CLOSING_FOR_COMPLETION_MESSAGE);
            }
            if ($operation->getSplitMode() !== MiningSplitMode::EachForThemselves) {
                throw new BadRequestHttpException(self::NOT_EACH_FOR_THEMSELVES_MESSAGE);
            }

            $valuation = $this->valuator->value($operation);
            $blocker = ($valuation->completionBlockers ?? throw new \LogicException('An each-for-themselves operation has completion blockers'))[0] ?? null;
            if ($blocker !== null) {
                throw new BadRequestHttpException($this->refusalOf($blocker, $valuation, self::QUANTITIES_NOT_KNOWN_FOR_COMPLETION_MESSAGE));
            }

            $operation->complete(
                $this->clock->now(),
                $valuation->pricesSyncedAt,
                $this->frozenUnitPricesOf($operation, $valuation),
                $this->frozenMinedValuesOf($operation, $valuation),
                $completedBy->getMainCharacter()?->getName(),
            );
            $this->entityManager->flush();
        });
    }

    /**
     * @return list<MiningFrozenUnitPrice>
     */
    private function frozenUnitPricesOf(MiningOperation $operation, MiningOperationValuation $valuation): array
    {
        $unitPrices = [];
        foreach ($valuation->unitPricesByTypeId as $typeId => $unitPrice) {
            $unitPrices[] = new MiningFrozenUnitPrice($operation, $typeId, $unitPrice ?? throw new \LogicException('A price is known once no price is missing'));
        }

        return $unitPrices;
    }

    /**
     * @return list<MiningFrozenMinedValue>
     */
    private function frozenMinedValuesOf(MiningOperation $operation, MiningOperationValuation $valuation): array
    {
        $minedValues = [];
        foreach ($valuation->participants as $position => $participant) {
            $minedValues[] = new MiningFrozenMinedValue(
                $operation,
                $participant->member,
                $participant->participantName,
                $position,
                (float) ($participant->minedValue ?? throw new \LogicException('A mined value is known once every quantity and price is known')),
                (float) ($participant->deliveredValue ?? throw new \LogicException('A delivered value is known once every quantity and price is known')),
            );
        }

        return $minedValues;
    }

    /**
     * @param list<MiningFrozenMinedValue> $frozenMinedValues
     */
    private function planPaymentsOf(MiningOperation $operation, MiningOperationValuation $valuation, array $frozenMinedValues): void
    {
        $split = $valuation->split ?? throw new \LogicException('A value prorata operation has a split');
        $minedValues = array_map(
            static fn (MiningParticipantValuation $participant): int => $participant->minedValue ?? throw new \LogicException('A mined value is known once every quantity and price is known'),
            $valuation->participants,
        );
        $keptValues = array_map(
            static fn (MiningParticipantValuation $participant): int => $participant->keptValue ?? throw new \LogicException('A kept value is known once every quantity and price is known'),
            $valuation->participants,
        );
        $shares = array_map(
            static fn (?int $share): int => $share ?? throw new \LogicException('Payments are planned once the shares are known'),
            $split->shares,
        );
        $boosterPosition = $this->positionOf($operation->getBoosterMember(), $frozenMinedValues);
        $recipientPosition = $this->positionOf($operation->getFleetStorageRecipient(), $frozenMinedValues);
        $corporationName = $this->corporationNameOf($operation);

        $settlement = $this->fleetStorageCalculator->settle($minedValues, $keptValues, $recipientPosition, $shares);
        $plannedPayments = $this->paymentPlanner->plan($settlement->heldValues, $split, $boosterPosition, $operation->getCorporationCutPercent() > 0);
        foreach ($settlement->debts as $depositorPosition => $debt) {
            if ($debt > 0) {
                $plannedPayments[] = new MiningPlannedPayment(count($plannedPayments), MiningPaymentKind::FleetStorageShare, $recipientPosition, $depositorPosition, $debt);
            }
        }

        foreach ($plannedPayments as $planned) {
            $payer = $frozenMinedValues[$planned->payerPosition ?? throw new \LogicException('A payment is owed by a participant')];
            $beneficiary = $planned->beneficiaryPosition === null ? null : $frozenMinedValues[$planned->beneficiaryPosition];
            $operation->getPayments()->add(new MiningPayment(
                $operation,
                $planned->position,
                $planned->kind,
                $payer->getMember(),
                $payer->getParticipantName(),
                $beneficiary?->getMember(),
                $beneficiary === null ? $corporationName : $beneficiary->getParticipantName(),
                (float) $planned->amount,
            ));
        }
    }

    /**
     * @param list<MiningFrozenMinedValue> $frozenMinedValues
     */
    private function positionOf(?MiningOperationMember $member, array $frozenMinedValues): ?int
    {
        foreach ($frozenMinedValues as $position => $frozenMinedValue) {
            if ($member !== null && $frozenMinedValue->getMember() === $member) {
                return $position;
            }
        }

        return null;
    }

    private function corporationNameOf(MiningOperation $operation): ?string
    {
        $owner = $operation->getOwner();

        return $operation->getCorporationId() !== null && $owner->getCorporationId() === $operation->getCorporationId()
            ? $owner->getCorporationName()
            : null;
    }

    private function refusalOf(MiningShareBlocker $blocker, MiningOperationValuation $valuation, string $quantitiesNotKnownMessage): string
    {
        return match ($blocker) {
            MiningShareBlocker::NotClosing => self::NOT_CLOSING_MESSAGE,
            MiningShareBlocker::EachForThemselves => self::EACH_FOR_THEMSELVES_MESSAGE,
            MiningShareBlocker::NotEachForThemselves => self::NOT_EACH_FOR_THEMSELVES_MESSAGE,
            MiningShareBlocker::QuantitiesNotKnown => sprintf($quantitiesNotKnownMessage, implode(', ', $this->unknownParticipantNamesOf($valuation))),
            MiningShareBlocker::PricesMissing => sprintf(self::PRICES_MISSING_MESSAGE, implode(', ', $this->missingTypeNamesOf($valuation))),
            MiningShareBlocker::BoosterMissing => self::BOOSTER_MISSING_MESSAGE,
            MiningShareBlocker::FleetStorageRecipientMissing => self::FLEET_STORAGE_RECIPIENT_MISSING_MESSAGE,
            MiningShareBlocker::FleetStorageExceedsMined => sprintf(self::FLEET_STORAGE_EXCEEDS_MINED_MESSAGE, implode(', ', $this->namesOfParticipantsDeliveringMoreThanMined($valuation))),
        };
    }

    /**
     * @return list<string>
     */
    private function unknownParticipantNamesOf(MiningOperationValuation $valuation): array
    {
        $names = [];
        foreach ($valuation->participants as $participant) {
            if ($participant->quantity?->state !== MinedQuantityState::Known) {
                $names[] = (string) $participant->participantName;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function namesOfParticipantsDeliveringMoreThanMined(MiningOperationValuation $valuation): array
    {
        $names = [];
        foreach ($valuation->participants as $participant) {
            if ($participant->deliversMoreThanMined()) {
                $names[] = (string) $participant->participantName;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function missingTypeNamesOf(MiningOperationValuation $valuation): array
    {
        $names = $this->typeNameResolver->findNames($valuation->missingPriceTypeIds);

        return array_map(
            static fn (int $typeId): string => $names[$typeId] ?? sprintf('Type %d', $typeId),
            $valuation->missingPriceTypeIds,
        );
    }
}
