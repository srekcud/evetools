<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Service\MiningOperation\CharacterEligibility;
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

        return $resource;
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

    private function inEveTime(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }

    private function inEveTimeOrNull(?\DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        return $moment === null ? null : $this->inEveTime($moment);
    }
}
