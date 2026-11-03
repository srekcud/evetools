<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\ApiResource\MiningOperation\MiningFleetStorageVerificationResource;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MiningCompressorKind;
use App\Enum\MiningShareBlocker;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\CharacterEligibility;
use App\Service\MiningOperation\MiningCharacterValuation;
use App\Service\MiningOperation\MiningFleetStorageVerification;
use App\Service\MiningOperation\MiningFleetStorageVerificationLine;
use App\Service\MiningOperation\MiningOperationValuation;
use App\Service\MiningOperation\MiningParticipantValuation;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\Service\MiningOperation\PersistedUuid;

final readonly class MiningOperationResourceMapper
{
    public function __construct(
        private MiningPresenceResolver $presenceResolver,
    ) {
    }

    public function operationToResource(MiningOperation $operation, ?MiningOperationMember $viewerMembership, bool $revealJoinCode = false): MiningOperationResource
    {
        $resource = new MiningOperationResource();
        $resource->id = $operation->getId()?->toRfc4122();
        $resource->label = $operation->getLabel();
        $resource->plannedStartAt = $this->inEveTime($operation->getPlannedStartAt());
        $resource->status = $operation->getStatus()->value;
        $resource->visibility = $operation->getVisibility()->value;
        $resource->splitMode = $operation->getSplitMode()->value;
        $resource->corporationId = $operation->getCorporationId();
        $resource->ownerCharacterName = $operation->getOwner()->getMainCharacter()?->getName();
        $acceptedMembers = $operation->acceptedMembers();
        $resource->participantsCount = count($acceptedMembers);
        $resource->charactersCount = array_sum(array_map(
            static fn (MiningOperationMember $member): int => $member->getCharacters()->count(),
            $acceptedMembers,
        ));
        $resource->joinCode = $revealJoinCode ? $operation->getJoinCode() : null;
        $resource->myRole = $viewerMembership?->getRole()->value;
        $resource->myStatus = $viewerMembership?->getStatus()->value;
        $resource->createdAt = $this->inEveTime($operation->getCreatedAt());
        $resource->startedAt = $this->inEveTimeOrNull($operation->getStartedAt());
        $resource->endedAt = $this->inEveTimeOrNull($operation->getEndedAt());
        $resource->solarSystemId = $operation->getSolarSystemId();
        $resource->solarSystemName = $operation->getSolarSystemName();
        $resource->myDepartedAt = $this->inEveTimeOrNull($viewerMembership?->getDepartedAt());
        $resource->valuationSettings = $this->valuationSettingsOf($operation);
        $resource->sharesFrozenAt = $this->inEveTimeOrNull($operation->getSharesFrozenAt());
        $resource->archivedAt = $this->inEveTimeOrNull($operation->getArchivedAt());
        $resource->archivedByName = $operation->getArchivedByName();
        $resource->fleetStorageRecipientName = $this->fleetStorageRecipientNameOf($operation);
        $recurrence = $operation->getRecurrence();
        $resource->recurrenceId = $recurrence === null ? null : PersistedUuid::of($recurrence->getId());
        $chunk = $operation->getChunk();
        $resource->chunkId = $chunk === null ? null : PersistedUuid::of($chunk->getId());

        return $resource;
    }

    public function paymentToResource(MiningPayment $payment): MiningPaymentResource
    {
        $payerMember = $payment->getPayerMember();
        $beneficiaryMember = $payment->getBeneficiaryMember();

        $resource = new MiningPaymentResource();
        $resource->id = PersistedUuid::of($payment->getId());
        $resource->position = $payment->getPosition();
        $resource->kind = $payment->getKind()->value;
        $resource->payerMemberId = $payerMember === null ? null : PersistedUuid::of($payerMember->getId());
        $resource->payerName = $payment->getPayerName();
        $resource->beneficiaryMemberId = $beneficiaryMember === null ? null : PersistedUuid::of($beneficiaryMember->getId());
        $resource->beneficiaryName = $payment->getBeneficiaryName();
        $resource->amount = (int) $payment->getAmount();
        $resource->paid = $payment->isPaid();
        $resource->paidAt = $this->inEveTimeOrNull($payment->getPaidAt());
        $resource->paidByName = $payment->getPaidByName();

        return $resource;
    }

    /**
     * @param array<int, string> $typeNames
     */
    public function valuationToResource(MiningOperation $operation, MiningOperationValuation $valuation, array $typeNames, bool $canAdminister): MiningOperationValuationResource
    {
        $split = $valuation->split;
        $freezeBlockers = $valuation->frozen ? [MiningShareBlocker::NotClosing] : $valuation->freezeBlockers;

        $resource = new MiningOperationValuationResource();
        $resource->operationId = PersistedUuid::of($operation->getId());
        $resource->status = $operation->getStatus()->value;
        $resource->splitMode = $operation->getSplitMode()->value;
        $resource->frozen = $valuation->frozen;
        $resource->sharesFrozenAt = $this->inEveTimeOrNull($operation->getSharesFrozenAt());
        $resource->pricesReadAt = $this->inEveTime($valuation->pricesReadAt);
        $resource->pricesSyncedAt = $this->inEveTimeOrNull($valuation->pricesSyncedAt);
        $resource->settings = $this->valuationSettingsOf($operation);
        $resource->unitPrices = $this->unitPricesToArray($valuation, $typeNames);
        $resource->missingPriceTypeIds = $valuation->missingPriceTypeIds;
        $resource->totalValue = $valuation->totalValue;
        $resource->boosterCut = $split?->boosterCut;
        $resource->corporationCut = $split?->corporationCut;
        $resource->iskPerHour = $valuation->iskPerHour;
        $resource->durationSeconds = $valuation->durationSeconds;
        $resource->roundingRemainder = $split?->roundingRemainder;
        $resource->boosterRoundingAdjustment = $split?->boosterRoundingAdjustment;
        $resource->corporationRoundingAdjustment = $split?->corporationRoundingAdjustment;
        $resource->participants = array_map(
            fn (MiningParticipantValuation $participant): array => $this->valuedParticipantToArray($operation, $participant),
            $valuation->participants,
        );
        $resource->canFreeze = $canAdminister && $freezeBlockers === [];
        $resource->freezeBlockers = array_map(static fn (MiningShareBlocker $blocker): string => $blocker->value, $freezeBlockers);
        $completionBlockers = $valuation->completionBlockers;
        $resource->canComplete = $canAdminister && !$valuation->frozen && $completionBlockers === [];
        $resource->completionBlockers = $completionBlockers === null
            ? null
            : array_map(static fn (MiningShareBlocker $blocker): string => $blocker->value, $completionBlockers);
        $fleetStorageRecipient = $operation->getFleetStorageRecipient();
        $resource->fleetStorageRecipientMemberId = $fleetStorageRecipient === null ? null : PersistedUuid::of($fleetStorageRecipient->getId());
        $resource->fleetStorageRecipientName = $this->fleetStorageRecipientNameOf($operation);

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function valuationSettingsOf(MiningOperation $operation): array
    {
        $boosterMember = $operation->getBoosterMember();

        return [
            'valuationForm' => $operation->getValuationForm()->value,
            'valuationMarket' => $operation->getValuationMarket()->value,
            'valuationSide' => $operation->getValuationSide()->value,
            'structureId' => $operation->getValuationStructureId(),
            'structureName' => $operation->getValuationStructureName(),
            'reprocessYield' => $operation->getReprocessYield(),
            'boosterCutPercent' => $operation->getBoosterCutPercent(),
            'corporationCutPercent' => $operation->getCorporationCutPercent(),
            'boosterMemberId' => $boosterMember === null ? null : PersistedUuid::of($boosterMember->getId()),
            'boosterCharacterName' => $boosterMember?->getUser()->getMainCharacter()?->getName(),
            'boostShipTypeId' => $operation->getBoostShip()?->value,
            'mountedCompressorKinds' => array_map(
                static fn (MiningCompressorKind $kind): string => $kind->value,
                $operation->getMountedCompressorKinds(),
            ),
        ];
    }

    public function memberToResource(MiningOperationMember $member): MiningOperationMemberResource
    {
        $user = $member->getUser();
        $resource = new MiningOperationMemberResource();
        $resource->id = PersistedUuid::of($member->getId());
        $resource->characterName = $user->getMainCharacter()?->getName();
        $resource->corporationId = $user->getCorporationId();
        $resource->role = $member->getRole()->value;
        $resource->status = $member->getStatus()->value;
        $resource->joinedAt = $this->inEveTime($member->getJoinedAt());
        $resource->departedAt = $this->inEveTimeOrNull($member->getDepartedAt());

        $presence = $this->presenceResolver->resolve($member);
        $resource->presence = $presence?->state->value;
        $resource->presenceIdleMinutes = $presence?->idleMinutes;

        $enrolments = array_values($member->getCharacters()->toArray());
        usort($enrolments, static fn (MiningOperationCharacter $left, MiningOperationCharacter $right): int => $left->getCharacter()->getName() <=> $right->getCharacter()->getName());
        $resource->characters = array_map(static fn (MiningOperationCharacter $enrolment): array => [
            'id' => PersistedUuid::of($enrolment->getCharacter()->getId()),
            'eveCharacterId' => $enrolment->getCharacter()->getEveCharacterId(),
            'name' => $enrolment->getCharacter()->getName(),
        ], $enrolments);

        return $resource;
    }

    /**
     * @param list<CharacterEligibility> $eligibilities
     */
    public function invitationToResource(MiningOperation $operation, ?MiningOperationMember $viewerMembership, bool $autoAccepted, array $eligibilities): MiningOperationInvitationResource
    {
        $resource = new MiningOperationInvitationResource();
        $resource->code = $operation->getJoinCode();
        $resource->operationId = PersistedUuid::of($operation->getId());
        $resource->label = $operation->getLabel();
        $resource->ownerCharacterName = $operation->getOwner()->getMainCharacter()?->getName();
        $resource->plannedStartAt = $this->inEveTime($operation->getPlannedStartAt());
        $resource->status = $operation->getStatus()->value;
        $resource->visibility = $operation->getVisibility()->value;
        $resource->splitMode = $operation->getSplitMode()->value;
        $resource->participantsCount = count($operation->acceptedMembers());
        $resource->valuationSettings = array_diff_key($this->valuationSettingsOf($operation), ['boosterMemberId' => true]);
        $resource->fleetStorageRecipientName = $this->fleetStorageRecipientNameOf($operation);
        $resource->autoAccepted = $autoAccepted;
        $resource->myRole = $viewerMembership?->getRole()->value;
        $resource->myStatus = $viewerMembership?->getStatus()->value;
        $resource->characters = array_map(static fn (CharacterEligibility $eligibility): array => [
            'id' => PersistedUuid::of($eligibility->character->getId()),
            'eveCharacterId' => $eligibility->character->getEveCharacterId(),
            'name' => $eligibility->character->getName(),
            'isMain' => $eligibility->character->isMain(),
            'eligibility' => $eligibility->eligibility->value,
            'busyOperationLabel' => $eligibility->busyOperationLabel,
        ], $eligibilities);

        return $resource;
    }

    /**
     * @return list<int>
     */
    public function fleetStorageTypeIdsOf(MiningOperation $operation): array
    {
        $typeIds = [];
        foreach ($operation->participantsInLedgerOrder() as $member) {
            foreach (array_keys($member->getFleetStorageQuantities() ?? []) as $typeId) {
                $typeIds[$typeId] = $typeId;
            }
        }

        return array_values($typeIds);
    }

    /**
     * @param array<int, string> $typeNames
     */
    public function fleetStorageToResource(MiningOperation $operation, User $viewer, bool $canAdminister, array $typeNames): MiningFleetStorageResource
    {
        $available = $operation->getSplitMode() !== MiningSplitMode::EachForThemselves;
        $editable = $available && $operation->getStatus()->isOpen();
        $recipient = $operation->getFleetStorageRecipient();

        $resource = new MiningFleetStorageResource();
        $resource->operationId = PersistedUuid::of($operation->getId());
        $resource->available = $available;
        $resource->editable = $editable;
        $resource->recipientMemberId = $recipient === null ? null : PersistedUuid::of($recipient->getId());
        $resource->recipientName = $this->fleetStorageRecipientNameOf($operation);
        $resource->containerName = $operation->getFleetStorageContainerName();
        $resource->declarations = array_map(
            fn (MiningOperationMember $member): array => [
                'memberId' => PersistedUuid::of($member->getId()),
                'characterName' => $member->getUser()->getMainCharacter()?->getName(),
                'delivery' => $member->getFleetStorageDelivery()->value,
                'quantities' => $this->declaredQuantitiesOf($member, $typeNames),
                'canDeclare' => $editable && $member !== $recipient && ($canAdminister || $member->getUser() === $viewer),
            ],
            $operation->participantsInLedgerOrder(),
        );

        return $resource;
    }

    public function verificationToResource(MiningOperation $operation, MiningFleetStorageVerification $verification): MiningFleetStorageVerificationResource
    {
        $resource = new MiningFleetStorageVerificationResource();
        $resource->operationId = PersistedUuid::of($operation->getId());
        $resource->status = $verification->status->value;
        $resource->unknownReason = $verification->unknownReason?->value;
        $resource->containerName = $verification->containerName;
        $resource->assetsReadAt = $this->inEveTimeOrNull($verification->assetsReadAt);
        $resource->lines = array_map(static fn (MiningFleetStorageVerificationLine $line): array => [
            'typeId' => $line->typeId,
            'typeName' => $line->typeName,
            'expectedQuantity' => $line->expectedQuantity,
            'foundQuantity' => $line->foundQuantity,
            'missingQuantity' => $line->missingQuantity,
            'status' => $line->status->value,
        ], $verification->lines);

        return $resource;
    }

    private function fleetStorageRecipientNameOf(MiningOperation $operation): ?string
    {
        return $operation->getFleetStorageRecipient()?->getUser()->getMainCharacter()?->getName();
    }

    /**
     * @param array<int, string> $typeNames
     *
     * @return list<array{typeId: int, typeName: string, quantity: int}>
     */
    private function declaredQuantitiesOf(MiningOperationMember $member, array $typeNames): array
    {
        $quantities = [];
        foreach ($member->getFleetStorageQuantities() ?? [] as $typeId => $quantity) {
            $quantities[] = ['typeId' => $typeId, 'typeName' => $typeNames[$typeId] ?? sprintf('Type %d', $typeId), 'quantity' => $quantity];
        }
        usort($quantities, static fn (array $left, array $right): int => $left['typeName'] <=> $right['typeName']);

        return $quantities;
    }

    /**
     * @param array<int, string> $typeNames
     *
     * @return list<array{typeId: int, typeName: string, unitPrice: ?float}>
     */
    private function unitPricesToArray(MiningOperationValuation $valuation, array $typeNames): array
    {
        $unitPrices = [];
        foreach ($valuation->unitPricesByTypeId as $typeId => $unitPrice) {
            $unitPrices[] = ['typeId' => $typeId, 'typeName' => $typeNames[$typeId] ?? sprintf('Type %d', $typeId), 'unitPrice' => $unitPrice];
        }
        usort($unitPrices, static fn (array $left, array $right): int => $left['typeName'] <=> $right['typeName']);

        return $unitPrices;
    }

    /**
     * @return array<string, mixed>
     */
    private function valuedParticipantToArray(MiningOperation $operation, MiningParticipantValuation $participant): array
    {
        $member = $participant->member;

        return [
            'memberId' => $member === null ? null : PersistedUuid::of($member->getId()),
            'characterName' => $participant->participantName,
            'isBooster' => $member !== null && $member === $operation->getBoosterMember(),
            'quantityState' => $participant->quantity?->state->value,
            'totalQuantity' => $participant->quantity?->totalQuantity(),
            'minedValue' => $participant->minedValue,
            'valuePercent' => $participant->valuePercent,
            'share' => $participant->share,
            'roundingAdjustment' => $participant->roundingAdjustment,
            'iskPerHour' => $participant->iskPerHour,
            'durationSeconds' => $participant->durationSeconds,
            'departedAt' => $this->inEveTimeOrNull($member?->getDepartedAt())?->format(\DateTimeInterface::ATOM),
            'characters' => array_map(static fn (MiningCharacterValuation $character): array => [
                'id' => PersistedUuid::of($character->operationCharacter->getId()),
                'name' => $character->operationCharacter->getCharacter()->getName(),
                'quantityState' => $character->quantity->state->value,
                'totalQuantity' => $character->quantity->totalQuantity(),
                'minedValue' => $character->minedValue,
            ], $participant->characters),
            'fleetStorageDelivery' => $participant->fleetStorageDelivery?->value,
            'isFleetStorageRecipient' => $participant->isFleetStorageRecipient,
            'deliveredValue' => $participant->deliveredValue,
            'keptValue' => $participant->keptValue,
            'fleetStorageDebt' => $participant->fleetStorageDebt,
        ];
    }

    private function inEveTime(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }

    private function inEveTimeOrNull(?\DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        return $moment === null ? null : $this->inEveTime($moment);
    }
}
