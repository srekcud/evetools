<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Enum\ContributionStatus;
use App\Enum\GroupMemberStatus;
use App\Service\GroupIndustry\PilotName;

class GroupIndustryResourceMapper
{
    /**
     * @param int|null $acceptedMembersCount precomputed by collection providers; counted from the members otherwise
     * @param float|null $approvedContributionValue precomputed by collection providers; summed from the approved contributions otherwise
     */
    public function projectToResource(
        GroupIndustryProject $project,
        ?GroupIndustryProjectMember $myMembership,
        ?int $acceptedMembersCount = null,
        ?float $approvedContributionValue = null,
    ): GroupIndustryProjectResource
    {
        $resource = new GroupIndustryProjectResource();
        $resource->id = $project->getId()->toString();
        $resource->name = $project->getName();
        $resource->status = $project->getStatus()->value;
        $resource->shortLinkCode = $project->getShortLinkCode();
        $resource->containerName = $project->getContainerName();
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
            ->filter(fn (GroupIndustryProjectMember $m) => $m->getStatus() === GroupMemberStatus::Accepted)
            ->count();

        $materialItems = $project->getBomItems()->filter(static fn (GroupIndustryBomItem $bomItem) => !$bomItem->isJob());
        $resource->totalBomValue = $this->totalBomValueOf($materialItems->toArray());
        $resource->fulfillmentPercent = $materialItems->isEmpty()
            ? 0.0
            : round(array_sum($materialItems->map(static fn (GroupIndustryBomItem $bomItem) => $bomItem->getFulfillmentPercent())->toArray()) / $materialItems->count(), 2);
        $resource->approvedContributionValue = $approvedContributionValue ?? array_sum(
            $project->getContributions()
                ->filter(static fn (GroupIndustryContribution $contribution) => $contribution->getStatus() === ContributionStatus::Approved)
                ->map(static fn (GroupIndustryContribution $contribution) => $contribution->getEstimatedValue())
                ->toArray(),
        );

        return $resource;
    }

    /**
     * @param GroupIndustryBomItem[] $materialItems
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

    public function contributionToResource(GroupIndustryContribution $contribution): GroupIndustryContributionResource
    {
        $resource = new GroupIndustryContributionResource();
        $resource->id = $contribution->getId()->toString();
        $resource->memberId = $contribution->getMember()->getId()->toString();
        $resource->memberCharacterName = PilotName::of($contribution->getMember()->getUser());
        $resource->bomItemId = $contribution->getBomItem()?->getId()?->toString();
        $resource->bomItemTypeName = $contribution->getBomItem()?->getTypeName();
        $resource->type = $contribution->getType()->value;
        $resource->quantity = $contribution->getQuantity();
        $resource->estimatedValue = $contribution->getEstimatedValue();
        $resource->status = $contribution->getStatus()->value;
        $resource->isAutoDetected = $contribution->isAutoDetected();
        $resource->isVerified = $contribution->isVerified();
        $resource->reviewedByCharacterName = $contribution->getReviewedBy()?->getMainCharacter()?->getName();
        $resource->reviewedAt = $contribution->getReviewedAt()?->format(\DateTimeInterface::ATOM);
        $resource->note = $contribution->getNote();
        $resource->createdAt = $contribution->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }

    public function memberToResource(GroupIndustryProjectMember $member, float $totalContributionValue = 0.0, int $contributionCount = 0): GroupIndustryMemberResource
    {
        $resource = new GroupIndustryMemberResource();
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
