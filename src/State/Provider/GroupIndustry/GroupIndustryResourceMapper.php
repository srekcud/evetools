<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Enum\GroupMemberStatus;

class GroupIndustryResourceMapper
{
    public function projectToResource(GroupIndustryProject $project, ?GroupIndustryProjectMember $myMembership): GroupIndustryProjectResource
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
        $resource->membersCount = $project->getMembers()
            ->filter(fn (GroupIndustryProjectMember $m) => $m->getStatus() === GroupMemberStatus::Accepted)
            ->count();

        // Calculate BOM value and fulfillment from material items (isJob=false)
        $totalBomValue = 0.0;
        $totalRequired = 0;
        $totalFulfilled = 0;
        foreach ($project->getBomItems() as $bomItem) {
            if (!$bomItem->isJob()) {
                $totalBomValue += ($bomItem->getEstimatedPrice() ?? 0.0) * $bomItem->getRequiredQuantity();
                $totalRequired += $bomItem->getRequiredQuantity();
                $totalFulfilled += min($bomItem->getFulfilledQuantity(), $bomItem->getRequiredQuantity());
            }
        }
        $resource->totalBomValue = $totalBomValue;
        $resource->fulfillmentPercent = $totalRequired > 0
            ? round(($totalFulfilled / $totalRequired) * 100, 2)
            : 0.0;

        return $resource;
    }

    public function contributionToResource(GroupIndustryContribution $contribution): GroupIndustryContributionResource
    {
        $resource = new GroupIndustryContributionResource();
        $resource->id = $contribution->getId()->toString();
        $resource->memberId = $contribution->getMember()->getId()->toString();
        $resource->memberCharacterName = $contribution->getMember()->getUser()->getMainCharacter()?->getName() ?? 'Unknown';
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
        $resource->characterName = $member->getUser()->getMainCharacter()?->getName() ?? 'Unknown';
        $resource->characterId = $member->getUser()->getMainCharacter()?->getEveCharacterId() ?? 0;
        $resource->corporationId = $member->getUser()->getCorporationId();
        $resource->role = $member->getRole()->value;
        $resource->status = $member->getStatus()->value;
        $resource->totalContributionValue = $totalContributionValue;
        $resource->contributionCount = $contributionCount;
        $resource->joinedAt = $member->getJoinedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
