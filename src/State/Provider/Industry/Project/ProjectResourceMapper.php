<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Industry\Project\ProjectParticipantResource;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectContributionService;

class ProjectResourceMapper
{
    /**
     * @param int|null $acceptedMembersCount precomputed by collection providers; counted from the members otherwise
     * @param float|null $approvedContributionValue precomputed by collection providers; summed from the approved contributions otherwise
     */
    public function projectToResource(
        IndustryProject $project,
        ?IndustryProjectParticipant $myMembership,
        ?int $acceptedMembersCount = null,
        ?float $approvedContributionValue = null,
        bool $approvedContributionValueIsPrecomputed = false,
    ): IndustryProjectResource
    {
        $resource = new IndustryProjectResource();
        $resource->id = $project->getId()->toString();
        $resource->name = $project->getName();
        $resource->status = $project->getStatus()->value;
        $resource->shortLinkCode = $project->getShortLinkCode();
        $resource->containerName = $project->getContainerName();
        $resource->private = $project->isPrivate();
        $resource->personalUse = $project->isPersonalUse();
        $resource->solo = $project->isSoloProjectOf($project->getOwner());
        $resource->acceptsOutsideFavoriteSystem = $project->isAcceptsOutsideFavoriteSystem();
        $resource->excludesInvention = $project->isExcludesInvention();
        $resource->minimumOutsideSaving = $project->getMinimumOutsideSaving();
        $resource->notes = $project->getNotes();
        $resource->sellStructureId = $project->getSellStructureId();
        $resource->sellStructureName = $project->getSellStructureName();
        $resource->ownerCharacterName = $project->getOwner()->getMainCharacter()?->getName() ?? 'Unknown';
        $resource->ownerCorporationId = $project->getOwner()->getCorporationId();
        $resource->brokerFeePercent = $project->getBrokerFeePercent();
        $resource->salesTaxPercent = $project->getSalesTaxPercent();
        $resource->lineRentalRatesOverride = $project->getLineRentalRatesOverride();
        $resource->blacklistGroupIds = $project->getBlacklistGroupIds();
        $resource->blacklistTypeIds = $project->getBlacklistTypeIds();
        $resource->createdAt = $project->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $resource->myRole = $myMembership?->getRole()->value;
        $resource->myStatus = $myMembership?->getStatus()->value;

        // Map project items
        $resource->items = [];
        foreach ($project->getItems() as $item) {
            $resource->items[] = [
                'typeId' => $item->getTypeId(),
                'typeName' => $item->getTypeName(),
                'meLevel' => $item->getMeLevel(),
                'teLevel' => $item->getTeLevel(),
                'runs' => $item->getRuns(),
            ];
        }

        // Count accepted members only
        $resource->membersCount = $acceptedMembersCount ?? $project->getMembers()
            ->filter(fn (IndustryProjectParticipant $m) => $m->getStatus() === ParticipantStatus::Accepted)
            ->count();

        $materialItems = $project->getBomItems()->filter(static fn (IndustryProjectBomLine $bomItem) => !$bomItem->isJob());
        $resource->totalBomValue = $this->totalBomValueOf($materialItems->toArray());
        $resource->fulfillmentPercent = $materialItems->isEmpty()
            ? 0.0
            : round(array_sum($materialItems->map(static fn (IndustryProjectBomLine $bomItem) => $bomItem->getFulfillmentPercent())->toArray()) / $materialItems->count(), 2);
        $resource->approvedContributionValue = $approvedContributionValue !== null || $approvedContributionValueIsPrecomputed
            ? $approvedContributionValue
            : $this->approvedValueOf($project);
        $resource->pendingContributionCount = $this->canReview($myMembership) ? $this->pendingCountOf($project) : null;

        return $resource;
    }

    private function canReview(?IndustryProjectParticipant $membership): bool
    {
        return $membership !== null
            && $membership->getStatus() === ParticipantStatus::Accepted
            && in_array($membership->getRole(), [ParticipantRole::Owner, ParticipantRole::Admin], true);
    }

    private function pendingCountOf(IndustryProject $project): int
    {
        return $project->getContributions()
            ->filter(static fn (IndustryProjectContribution $contribution) => $contribution->getStatus() === ContributionStatus::Pending)
            ->count();
    }

    private function approvedValueOf(IndustryProject $project): ?float
    {
        $total = 0.0;
        foreach ($project->getContributions() as $contribution) {
            if ($contribution->getStatus() !== ContributionStatus::Approved) {
                continue;
            }
            $value = $contribution->getEstimatedValue();
            if ($value === null) {
                return null;
            }
            $total += $value;
        }

        return $total;
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     */
    private function totalBomValueOf(array $materialItems): ?float
    {
        $total = 0.0;
        foreach ($materialItems as $bomItem) {
            $estimatedPrice = $bomItem->getEstimatedPrice();
            if ($estimatedPrice === null) {
                return null;
            }
            $total += $estimatedPrice * $bomItem->getRequiredQuantity();
        }

        return $total;
    }

    public function contributionToResource(IndustryProjectContribution $contribution): ProjectContributionResource
    {
        $resource = new ProjectContributionResource();
        $resource->id = $contribution->getId()->toString();
        $resource->memberId = $contribution->getMember()->getId()->toString();
        $resource->memberCharacterName = PilotName::of($contribution->getMember()->getUser());
        $resource->bomItemId = $contribution->getBomItem()?->getId()?->toString();
        $resource->bomItemTypeName = $contribution->getBomItem()?->getTypeName();
        $resource->type = $contribution->getType()->value;
        $resource->quantity = $contribution->getQuantity();
        $resource->estimatedValue = $contribution->getEstimatedValue();
        $resource->estimatedValueSource = ContributionType::Material === $contribution->getType() && null !== $contribution->getEstimatedValue() ? ProjectContributionService::materialContributionPriceSource()->toArray() : null;
        $resource->status = $contribution->getStatus()->value;
        $resource->isAutoDetected = $contribution->isAutoDetected();
        $resource->duplicatesDerived = $contribution->duplicatesDerived();
        $resource->isVerified = $contribution->isVerified();
        $resource->reviewedByCharacterName = $contribution->getReviewedBy()?->getMainCharacter()?->getName();
        $resource->reviewedAt = $contribution->getReviewedAt()?->format(\DateTimeInterface::ATOM);
        $resource->note = $contribution->getNote();
        $resource->createdAt = $contribution->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }

    public function memberToResource(IndustryProjectParticipant $member, ?float $totalContributionValue = 0.0, int $contributionCount = 0): ProjectParticipantResource
    {
        $resource = new ProjectParticipantResource();
        $resource->id = $member->getId()->toString();
        $user = $member->getUser();
        $resource->characterName = PilotName::of($user);
        $resource->characterId = $user?->getMainCharacter()?->getEveCharacterId();
        $resource->corporationId = $user?->getCorporationId();
        $resource->corporationName = $user?->getCorporationName();
        $resource->role = $member->getRole()->value;
        $resource->status = $member->getStatus()->value;
        $resource->totalContributionValue = $totalContributionValue;
        $resource->contributionCount = $contributionCount;
        $resource->joinedAt = $member->getJoinedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
