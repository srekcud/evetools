<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\PriceSource;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class ProjectContributionService
{
    private const float DEPOSIT_DAY_PERCENTILE = 0.05;

    public static function materialContributionPriceSource(): PriceSource
    {
        return PriceSource::jitaSellPercentile(self::DEPOSIT_DAY_PERCENTILE * PriceSource::PERCENT_PER_FRACTION);
    }

    public function __construct(
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaMarketService $jitaMarket,
        private readonly LineReservationService $lineReservations,
    ) {
    }

    /**
     * Submit a manual contribution from a project member.
     *
     * @throws \DomainException if a duplicate pending/approved contribution exists
     */
    public function submit(
        IndustryProjectParticipant $member,
        IndustryProjectBomLine $bomItem,
        ContributionType $type,
        int $quantity,
        ?float $estimatedValue = null,
        ?string $note = null,
    ): IndustryProjectContribution {
        $this->validateAntiDuplicate($member, $bomItem, $type);

        if ($quantity > $bomItem->getRemainingQuantity()) {
            throw new \DomainException('The contribution exceeds the remaining quantity of the line');
        }

        $estimatedValue = match ($type) {
            ContributionType::Material => $this->resolveMaterialValue($bomItem, $quantity, $estimatedValue),
            ContributionType::JobInstall, ContributionType::Bpc => $this->requirePositiveEnteredValue($type, $estimatedValue),
            ContributionType::LineRental => $this->requireEnteredValue($type, $estimatedValue),
            ContributionType::Courier => throw new \DomainException('The courier reward is derived from the linked courier contract and cannot be submitted'),
        };

        $contribution = new IndustryProjectContribution();
        $contribution->setProject($member->getProject());
        $contribution->setMember($member);
        $contribution->setBomItem($bomItem);
        $contribution->setType($type);
        $contribution->setQuantity($quantity);
        $contribution->setEstimatedValue($estimatedValue);
        $contribution->setStatus(ContributionStatus::Pending);
        $contribution->setIsAutoDetected(false);
        $contribution->setIsVerified(false);
        $contribution->setNote($note);

        $this->entityManager->persist($contribution);

        $contributor = $member->getUser();
        if ($contributor !== null && $member->getProject()->isSoloProjectOf($contributor)) {
            $this->approve($contribution, $contributor);

            return $contribution;
        }

        $this->entityManager->flush();

        return $contribution;
    }

    /**
     * Approve a pending contribution. Increments the BOM item fulfillment.
     *
     * @throws \DomainException if contribution is not pending
     */
    public function approve(IndustryProjectContribution $contribution, User $reviewer): void
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
            $this->lineReservations->settleApproval($bomItem, $contribution->getMember(), $contribution->getQuantity());
        }

        $this->entityManager->flush();
    }

    /**
     * Reject a pending contribution. Does NOT modify BOM item fulfillment.
     *
     * @throws \DomainException if contribution is not pending
     */
    public function reject(IndustryProjectContribution $contribution, User $reviewer): void
    {
        $isDuplicate = $contribution->duplicatesDerived();
        if ($contribution->getStatus() !== ContributionStatus::Pending && !$isDuplicate) {
            throw new \DomainException(
                sprintf('Cannot reject a contribution with status "%s"', $contribution->getStatus()->value),
            );
        }

        $bomItem = $contribution->getBomItem();
        if ($isDuplicate && $bomItem !== null && ContributionType::Material === $contribution->getType()) {
            $bomItem->setFulfilledQuantity(max(0, $bomItem->getFulfilledQuantity() - $contribution->getQuantity()));
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
        IndustryProjectParticipant $member,
        IndustryProjectBomLine $bomItem,
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

    private function resolveMaterialValue(IndustryProjectBomLine $bomItem, int $quantity, ?float $enteredValue): float
    {
        if ($enteredValue !== null) {
            throw new \DomainException('A material contribution takes no entered value: it is valued at the Jita price of the deposit day.');
        }

        $typeId = $bomItem->getTypeId();
        $jitaPrice = $this->jitaMarket->getCheapestPercentilePrices([$typeId], self::DEPOSIT_DAY_PERCENTILE)[$typeId] ?? null;

        if ($jitaPrice === null || $jitaPrice <= 0) {
            throw new \DomainException(sprintf('No market price for type %d: no Jita price for the deposit day.', $typeId));
        }

        return $jitaPrice * $quantity;
    }

    private function requireEnteredValue(ContributionType $type, ?float $enteredValue): float
    {
        return $enteredValue
            ?? throw new \DomainException(sprintf('An estimated value is required for a %s contribution.', $type->value));
    }

    private function requirePositiveEnteredValue(ContributionType $type, ?float $enteredValue): float
    {
        $value = $this->requireEnteredValue($type, $enteredValue);
        if ($value <= 0) {
            throw new \DomainException(sprintf('The estimated value of a %s contribution must be positive.', $type->value));
        }

        return $value;
    }

    private function incrementFulfillment(IndustryProjectBomLine $bomItem, int $quantity): void
    {
        $bomItem->setFulfilledQuantity($bomItem->getFulfilledQuantity() + $quantity);
    }
}
