<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\GroupIndustryContributionRepository;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class GroupIndustryContributionService
{
    public function __construct(
        private readonly GroupIndustryContributionRepository $contributionRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Submit a manual contribution from a project member.
     *
     * @throws \DomainException if a duplicate pending/approved contribution exists
     */
    public function submit(
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        ContributionType $type,
        int $quantity,
        ?float $estimatedValue = null,
        ?string $note = null,
    ): GroupIndustryContribution {
        $this->validateAntiDuplicate($member, $bomItem, $type);

        if ($estimatedValue === null && $type === ContributionType::Material) {
            $estimatedValue = $this->calculateMaterialValue($bomItem->getTypeId(), $quantity);
        }

        $contribution = new GroupIndustryContribution();
        $contribution->setProject($member->getProject());
        $contribution->setMember($member);
        $contribution->setBomItem($bomItem);
        $contribution->setType($type);
        $contribution->setQuantity($quantity);
        $contribution->setEstimatedValue($estimatedValue ?? 0.0);
        $contribution->setStatus(ContributionStatus::Pending);
        $contribution->setIsAutoDetected(false);
        $contribution->setIsVerified(false);
        $contribution->setNote($note);

        $this->entityManager->persist($contribution);
        $this->entityManager->flush();

        return $contribution;
    }

    /**
     * Submit an auto-detected contribution (from ESI industry jobs sync).
     * Auto-detected contributions are auto-approved and immediately increment fulfillment.
     */
    public function submitAutoDetected(
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        ContributionType $type,
        int $quantity,
        float $estimatedValue,
    ): GroupIndustryContribution {
        $this->validateAntiDuplicate($member, $bomItem, $type);

        $contribution = new GroupIndustryContribution();
        $contribution->setProject($member->getProject());
        $contribution->setMember($member);
        $contribution->setBomItem($bomItem);
        $contribution->setType($type);
        $contribution->setQuantity($quantity);
        $contribution->setEstimatedValue($estimatedValue);
        $contribution->setStatus(ContributionStatus::Approved);
        $contribution->setIsAutoDetected(true);
        $contribution->setIsVerified(true);

        $this->incrementFulfillment($bomItem, $quantity);

        $this->entityManager->persist($contribution);
        $this->entityManager->flush();

        return $contribution;
    }

    /**
     * Approve a pending contribution. Increments the BOM item fulfillment.
     *
     * @throws \DomainException if contribution is not pending
     */
    public function approve(GroupIndustryContribution $contribution, User $reviewer): void
    {
        if ($contribution->getStatus() !== ContributionStatus::Pending) {
            throw new \DomainException(
                sprintf('Cannot approve a contribution with status "%s"', $contribution->getStatus()->value),
            );
        }

        $contribution->setStatus(ContributionStatus::Approved);
        $contribution->setReviewedBy($reviewer);
        $contribution->setReviewedAt(new \DateTimeImmutable());

        $bomItem = $contribution->getBomItem();
        if ($bomItem !== null) {
            $this->incrementFulfillment($bomItem, $contribution->getQuantity());
        }

        $this->entityManager->flush();
    }

    /**
     * Reject a pending contribution. Does NOT modify BOM item fulfillment.
     *
     * @throws \DomainException if contribution is not pending
     */
    public function reject(GroupIndustryContribution $contribution, User $reviewer): void
    {
        if ($contribution->getStatus() !== ContributionStatus::Pending) {
            throw new \DomainException(
                sprintf('Cannot reject a contribution with status "%s"', $contribution->getStatus()->value),
            );
        }

        $contribution->setStatus(ContributionStatus::Rejected);
        $contribution->setReviewedBy($reviewer);
        $contribution->setReviewedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    /**
     * Check that no pending or approved contribution of the same type exists for this member/bomItem pair.
     *
     * @throws \DomainException if a duplicate contribution is found
     */
    public function validateAntiDuplicate(
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        ContributionType $type,
    ): void {
        $existing = $this->contributionRepository->findByMemberBomItemAndType(
            $member,
            $bomItem,
            $type,
            [ContributionStatus::Pending, ContributionStatus::Approved],
        );

        if ($existing !== null) {
            throw new \DomainException('A contribution of this type is already pending or approved for this item');
        }
    }

    /**
     * Calculate the Ravworks-style value for a material contribution.
     * Uses the cheapest 5% percentile of Jita sell orders.
     */
    public function calculateMaterialValue(int $typeId, int $quantity): float
    {
        $prices = $this->jitaMarketService->getCheapestPercentilePrices([$typeId]);
        $price = $prices[$typeId] ?? null;

        if ($price === null) {
            return 0.0;
        }

        return $price * $quantity;
    }

    private function incrementFulfillment(GroupIndustryBomItem $bomItem, int $quantity): void
    {
        $bomItem->setFulfilledQuantity($bomItem->getFulfilledQuantity() + $quantity);
    }
}
