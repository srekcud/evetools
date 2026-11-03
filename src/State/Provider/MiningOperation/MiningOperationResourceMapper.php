<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Enum\MiningShareBlocker;
use App\Service\MiningOperation\CharacterEligibility;
use App\Service\MiningOperation\MiningCharacterValuation;
use App\Service\MiningOperation\MiningOperationValuation;
use App\Service\MiningOperation\MiningParticipantValuation;
use App\Service\MiningOperation\PersistedUuid;

final readonly class MiningOperationResourceMapper
{
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
