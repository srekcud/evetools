<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\PriceSource;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class ProjectContributionService
{
    public const float DEPOSIT_DAY_PERCENTILE = 0.05;

    private const float DONATION_VALUE = 0.0;

    public static function materialContributionPriceSource(): PriceSource
    {
        return PriceSource::jitaSellPercentile(self::DEPOSIT_DAY_PERCENTILE * PriceSource::PERCENT_PER_FRACTION);
    }

    public function __construct(
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaMarketService $jitaMarket,
        private readonly LineReservationService $lineReservations,
        private readonly IndustryProjectService $projectService,
        private readonly ContributorPaidPurchaseReader $paidPurchases,
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
        ?ContributionValueSource $valueSource = null,
    ): IndustryProjectContribution {
        if (null !== $valueSource && (ContributionType::Material !== $type || !$this->acceptsAValueSource($bomItem, $valueSource))) {
            throw new \DomainException('A value source only applies to a material contribution of a material line');
        }

        if (ContributionValueSource::Donation === $valueSource) {
            throw new \DomainException('A donation can only be recorded by the project owner');
        }

        if ($this->isIntermediateContribution($bomItem, $type)) {
            if (ContributionValueSource::LaunchPrice !== $valueSource) {
                $this->assertSoloProject($member);
            }

            return $this->entityManager->wrapInTransaction(
                fn (): IndustryProjectContribution => $this->submitContribution($member, $bomItem, $type, $quantity, $estimatedValue, $note, $valueSource),
            );
        }

        return $this->submitContribution($member, $bomItem, $type, $quantity, $estimatedValue, $note, $valueSource);
    }

    private function submitContribution(
        IndustryProjectParticipant $member,
        IndustryProjectBomLine $bomItem,
        ContributionType $type,
        int $quantity,
        ?float $estimatedValue,
        ?string $note,
        ?ContributionValueSource $valueSource,
    ): IndustryProjectContribution {
        if (ContributionType::Material !== $type) {
            $this->validateAntiDuplicate($member, $bomItem, $type);
        }

        if ($quantity > $bomItem->getRemainingQuantity()) {
            throw new \DomainException('The contribution exceeds the remaining quantity of the line');
        }

        $value = match ($type) {
            ContributionType::Material => $this->resolveMaterialValue($member, $bomItem, $quantity, $estimatedValue, $valueSource ?? ContributionValueSource::JitaPrice),
            ContributionType::JobInstall, ContributionType::Bpc => new ContributionValue($this->requirePositiveEnteredValue($type, $estimatedValue)),
            ContributionType::LineRental => new ContributionValue($this->requireEnteredValue($type, $estimatedValue)),
            ContributionType::Courier => throw new \DomainException('The courier reward is derived from the linked courier contract and cannot be submitted'),
        };

        $contribution = new IndustryProjectContribution();
        $contribution->setProject($member->getProject());
        $contribution->setMember($member);
        $contribution->setBomItem($bomItem);
        $contribution->setType($type);
        $contribution->setQuantity($quantity);
        $contribution->setEstimatedValue($value->value);
        $contribution->setStatus(ContributionStatus::Pending);
        $contribution->setIsAutoDetected(false);
        $contribution->setIsVerified(false);
        $contribution->setValuedAtJitaPrice($value->valuedAtJitaPrice);
        $contribution->setValueSource($value->source);
        $contribution->setAwaitingQuantity($value->awaitingQuantity);
        $contribution->setNote($note);

        $this->entityManager->persist($contribution);
        $this->linkPaidPurchases($contribution, $bomItem, $value);

        $contributor = $member->getUser();
        if (0 === $contribution->getAwaitingQuantity() && $contributor !== null && $member->getProject()->isSoloProjectOf($contributor)) {
            if ($this->isIntermediateContribution($bomItem, $type)) {
                $this->approveContribution($contribution, $contributor, $bomItem);
                $this->recalculateBomOf($contribution);

                return $contribution;
            }

            $this->approve($contribution, $contributor);

            return $contribution;
        }

        if (0 === $contribution->getAwaitingQuantity() && $contributor !== null && $member->getProject()->isSoleReviewingOwner($contributor)) {
            $this->approve($contribution, $contributor);

            return $contribution;
        }

        $this->entityManager->flush();

        return $contribution;
    }

    public function assignUnclaimedStock(IndustryProjectParticipant $recipient, IndustryProjectBomLine $bomItem, int $quantity, User $owner): IndustryProjectContribution
    {
        return $this->recordUnclaimedStockAllocation($recipient, $bomItem, $quantity, $owner, ContributionValueSource::LaunchPrice);
    }

    public function donateUnclaimedStock(IndustryProjectParticipant $ownerParticipant, IndustryProjectBomLine $bomItem, int $quantity, User $owner): IndustryProjectContribution
    {
        return $this->recordUnclaimedStockAllocation($ownerParticipant, $bomItem, $quantity, $owner, ContributionValueSource::Donation);
    }

    private function recordUnclaimedStockAllocation(IndustryProjectParticipant $member, IndustryProjectBomLine $bomItem, int $quantity, User $owner, ContributionValueSource $source): IndustryProjectContribution
    {
        if (!$bomItem->isPurchasable()) {
            throw new \DomainException('Only the unclaimed stock of a material or component line can be allocated');
        }

        $value = $this->resolveMaterialValue($member, $bomItem, $quantity, null, $source);

        $contribution = new IndustryProjectContribution();
        $contribution->setProject($member->getProject());
        $contribution->setMember($member);
        $contribution->setBomItem($bomItem);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity($quantity);
        $contribution->setEstimatedValue($value->value);
        $contribution->setStatus(ContributionStatus::Pending);
        $contribution->setIsAutoDetected(false);
        $contribution->setIsVerified(false);
        $contribution->setValuedAtJitaPrice(false);
        $contribution->setValueSource($value->source);
        $contribution->setAwaitingQuantity(0);

        $this->entityManager->persist($contribution);
        $this->approve($contribution, $owner);

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

        if ($contribution->getAwaitingQuantity() > 0) {
            throw new \DomainException('Cannot approve a contribution while some of its units still await a wallet transaction');
        }

        $bomItem = $contribution->getBomItem();
        if (null !== $bomItem && $this->isIntermediateContribution($bomItem, $contribution->getType())) {
            $this->entityManager->wrapInTransaction(function () use ($contribution, $reviewer, $bomItem): void {
                $this->approveContribution($contribution, $reviewer, $bomItem);
                $this->recalculateBomOf($contribution);
            });

            return;
        }

        $this->approveContribution($contribution, $reviewer, $bomItem);
    }

    private function approveContribution(IndustryProjectContribution $contribution, User $reviewer, ?IndustryProjectBomLine $bomItem): void
    {
        $contribution->setStatus(ContributionStatus::Approved);
        $contribution->setReviewedBy($reviewer);
        $contribution->setReviewedAt(new \DateTimeImmutable());

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
        if ($isDuplicate && null !== $bomItem && $this->isIntermediateContribution($bomItem, $contribution->getType())) {
            $this->entityManager->wrapInTransaction(function () use ($contribution, $reviewer, $bomItem, $isDuplicate): void {
                $this->rejectContribution($contribution, $reviewer, $bomItem, $isDuplicate);
                $this->recalculateBomOf($contribution);
            });

            return;
        }

        $this->rejectContribution($contribution, $reviewer, $bomItem, $isDuplicate);
    }

    private function rejectContribution(IndustryProjectContribution $contribution, User $reviewer, ?IndustryProjectBomLine $bomItem, bool $isDuplicate): void
    {
        if ($isDuplicate && $bomItem !== null && ContributionType::Material === $contribution->getType()) {
            $bomItem->setFulfilledQuantity(max(0, $bomItem->getFulfilledQuantity() - $contribution->getQuantity()));
        }

        if ($bomItem !== null) {
            foreach ($bomItem->getPurchases()->toArray() as $purchase) {
                if ($purchase->getContribution() === $contribution) {
                    $bomItem->removePurchase($purchase);
                    $this->entityManager->remove($purchase);
                }
            }
        }

        $contribution->setStatus(ContributionStatus::Rejected);
        $contribution->setReviewedBy($reviewer);
        $contribution->setReviewedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    public function valueAtJitaPrice(IndustryProjectContribution $contribution): void
    {
        $bomItem = $contribution->getBomItem();
        if (null === $bomItem
            || ContributionStatus::Pending !== $contribution->getStatus()
            || ContributionValueSource::WalletPurchase !== $contribution->getValueSource()
            || $contribution->getAwaitingQuantity() <= 0) {
            throw new \DomainException('Only a pending wallet purchase contribution with units awaiting a transaction can be valued at the Jita price');
        }

        $jitaPrice = $this->depositDayJitaPrice($bomItem->getTypeId());
        $linkedValue = 0.0;
        foreach ($bomItem->getPurchases() as $purchase) {
            if ($purchase->getContribution() === $contribution) {
                $linkedValue += $purchase->getTotalPrice();
            }
        }

        $contribution->setEstimatedValue($linkedValue + $jitaPrice * $contribution->getAwaitingQuantity());
        $contribution->setAwaitingQuantity(0);
        $contribution->setValueSource(ContributionValueSource::JitaPrice);
        $contribution->setValuedAtJitaPrice(true);

        $this->entityManager->flush();
    }

    public function reopen(IndustryProjectContribution $contribution): void
    {
        $bomItem = $contribution->getBomItem();
        if (!$contribution->isReopenable() || null === $bomItem) {
            throw new \DomainException('Only an approved material contribution of a material line can be reopened, before the selling phase');
        }

        $bomItem->setFulfilledQuantity(max(0, $bomItem->getFulfilledQuantity() - $contribution->getQuantity()));
        $contribution->setStatus(ContributionStatus::Pending);
        $contribution->setReviewedBy(null);
        $contribution->setReviewedAt(null);

        $this->entityManager->flush();
    }

    /**
     * @throws \DomainException if the contribution is locked or the change breaks a submission rule
     */
    public function edit(IndustryProjectContribution $contribution, ?int $quantity, ?float $estimatedValue, ?string $note): void
    {
        $this->assertEditableByItsContributor($contribution);

        if (null === $quantity && null === $estimatedValue && null === $note) {
            throw new \DomainException('Nothing to change on the contribution');
        }

        $bomItem = $contribution->getBomItem();
        $isApproved = ContributionStatus::Approved === $contribution->getStatus();
        $previousQuantity = $contribution->getQuantity();
        $room = null === $bomItem ? 0 : $bomItem->getRemainingQuantity() + ($isApproved ? $previousQuantity : 0);
        if (null !== $quantity && null !== $bomItem && $quantity > $room) {
            throw new \DomainException('The contribution exceeds the remaining quantity of the line');
        }

        $type = $contribution->getType();
        $value = match ($type) {
            ContributionType::Material => $this->revaluedMaterialContribution($contribution, $quantity, $estimatedValue),
            ContributionType::JobInstall, ContributionType::Bpc => null === $estimatedValue ? null : new ContributionValue($this->requirePositiveEnteredValue($type, $estimatedValue)),
            ContributionType::LineRental => null === $estimatedValue ? null : new ContributionValue($estimatedValue),
            ContributionType::Courier => throw new \DomainException('A courier contribution is derived from its contract and cannot be edited'),
        };

        if (null !== $quantity) {
            $contribution->setQuantity($quantity);
        }
        if (null !== $value) {
            $contribution->setEstimatedValue($value->value);
            $contribution->setValuedAtJitaPrice($value->valuedAtJitaPrice);
        }
        if (null !== $value && null !== $bomItem && ContributionType::Material === $type) {
            $contribution->setValueSource($value->source);
            $contribution->setAwaitingQuantity($value->awaitingQuantity);
            $this->linkPaidPurchases($contribution, $bomItem, $value);
        }
        if (null !== $note) {
            $contribution->setNote('' === $note ? null : $note);
        }

        if ($isApproved && null !== $bomItem) {
            $this->settleRevisedApproval($contribution, $bomItem, $previousQuantity);

            return;
        }

        $this->entityManager->flush();
    }

    /**
     * @throws \DomainException if the contribution is locked
     */
    public function withdraw(IndustryProjectContribution $contribution): void
    {
        $this->assertEditableByItsContributor($contribution);

        $bomItem = $contribution->getBomItem();
        if (ContributionStatus::Approved === $contribution->getStatus() && null !== $bomItem) {
            $this->withdrawApproved($contribution, $bomItem);

            return;
        }

        $contribution->getProject()->getContributions()->removeElement($contribution);
        $this->entityManager->remove($contribution);
        $this->entityManager->flush();
    }

    private function settleRevisedApproval(IndustryProjectContribution $contribution, IndustryProjectBomLine $bomItem, int $previousQuantity): void
    {
        $this->entityManager->wrapInTransaction(function () use ($contribution, $bomItem, $previousQuantity): void {
            $stillAwaitingUnits = $contribution->getAwaitingQuantity() > 0;
            $bomItem->setFulfilledQuantity(max(0, $bomItem->getFulfilledQuantity() - $previousQuantity + ($stillAwaitingUnits ? 0 : $contribution->getQuantity())));
            if ($stillAwaitingUnits) {
                $contribution->setStatus(ContributionStatus::Pending);
                $contribution->setReviewedBy(null);
                $contribution->setReviewedAt(null);
            }

            $this->entityManager->flush();

            if ($this->isIntermediateContribution($bomItem, $contribution->getType())) {
                $this->recalculateBomOf($contribution);
            }
        });
    }

    private function withdrawApproved(IndustryProjectContribution $contribution, IndustryProjectBomLine $bomItem): void
    {
        $this->entityManager->wrapInTransaction(function () use ($contribution, $bomItem): void {
            $bomItem->setFulfilledQuantity(max(0, $bomItem->getFulfilledQuantity() - $contribution->getQuantity()));
            $this->unlinkPaidPurchases($contribution, $bomItem);
            $contribution->getProject()->getContributions()->removeElement($contribution);
            $this->entityManager->remove($contribution);
            $this->entityManager->flush();

            if ($this->isIntermediateContribution($bomItem, $contribution->getType())) {
                $this->recalculateBomOf($contribution);
            }
        });
    }

    private function assertEditableByItsContributor(IndustryProjectContribution $contribution): void
    {
        if (!$contribution->isEditableByItsContributor()) {
            throw new \DomainException('Only a pending contribution of a running project that is not auto-detected can be changed by its contributor');
        }
    }

    private function revaluedMaterialContribution(IndustryProjectContribution $contribution, ?int $quantity, ?float $estimatedValue): ?ContributionValue
    {
        if (null !== $estimatedValue) {
            throw new \DomainException('A material contribution takes no entered value: it is valued at the Jita price of the deposit day.');
        }

        $bomItem = $contribution->getBomItem();
        if (null === $quantity || null === $bomItem) {
            return null;
        }

        $this->unlinkPaidPurchases($contribution, $bomItem);

        return $this->resolveMaterialValue($contribution->getMember(), $bomItem, $quantity, null, $contribution->getValueSource() ?? ContributionValueSource::JitaPrice);
    }

    private function linkPaidPurchases(IndustryProjectContribution $contribution, IndustryProjectBomLine $bomItem, ContributionValue $value): void
    {
        foreach ($value->matches as $units) {
            $purchase = ProjectPurchaseFactory::fromUnits($units, $contribution->getMember(), $contribution);
            $bomItem->addPurchase($purchase);
            $this->entityManager->persist($purchase);
        }
    }

    private function unlinkPaidPurchases(IndustryProjectContribution $contribution, IndustryProjectBomLine $bomItem): void
    {
        foreach ($bomItem->getPurchases()->toArray() as $purchase) {
            if ($purchase->getContribution() === $contribution) {
                $bomItem->removePurchase($purchase);
                $this->entityManager->remove($purchase);
            }
        }
    }

    private function isIntermediateContribution(IndustryProjectBomLine $bomItem, ContributionType $type): bool
    {
        return ContributionType::Material === $type && $bomItem->isJob();
    }

    private function acceptsAValueSource(IndustryProjectBomLine $bomItem, ContributionValueSource $valueSource): bool
    {
        return !$bomItem->isJob() || (ContributionValueSource::LaunchPrice === $valueSource && $bomItem->isPurchasable());
    }

    private function assertSoloProject(IndustryProjectParticipant $member): void
    {
        $contributor = $member->getUser();
        if (null === $contributor || !$member->getProject()->isSoloProjectOf($contributor)) {
            throw new \DomainException('An intermediate contribution can only be made on a solo project.');
        }
    }

    private function recalculateBomOf(IndustryProjectContribution $contribution): void
    {
        $project = $contribution->getProject();

        try {
            $this->projectService->buildBom($project, $project->getOwner(), refuseToDropWorkedLines: true);
        } catch (\RuntimeException $refusal) {
            throw new \DomainException($refusal->getMessage(), 0, $refusal);
        }
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

    private function resolveMaterialValue(IndustryProjectParticipant $member, IndustryProjectBomLine $bomItem, int $quantity, ?float $enteredValue, ContributionValueSource $source): ContributionValue
    {
        if ($enteredValue !== null) {
            throw new \DomainException('A material contribution takes no entered value: it is valued at the Jita price of the deposit day.');
        }

        if (ContributionValueSource::LaunchPrice === $source) {
            return new ContributionValue($quantity * $this->requireLaunchPrice($bomItem), false, 0, $source);
        }

        if (ContributionValueSource::Donation === $source) {
            return new ContributionValue(self::DONATION_VALUE, false, 0, $source);
        }

        $typeId = $bomItem->getTypeId();
        $paid = $this->paidPurchases->read($member, $typeId);
        $matches = null === $paid ? [] : $paid->allocate($quantity);
        $matchedUnits = array_sum(array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $matches));
        $matchedValue = (float) array_sum(array_map(static fn (PaidTransactionUnits $units): float => $units->totalPrice(), $matches));
        $unmatchedUnits = $quantity - $matchedUnits;

        if (ContributionValueSource::WalletPurchase === $source) {
            return new ContributionValue([] === $matches ? null : $matchedValue, false, $unmatchedUnits, $source, $matches);
        }

        if (0 === $unmatchedUnits) {
            return new ContributionValue($matchedValue, false, 0, $source, $matches);
        }

        return new ContributionValue($matchedValue + $this->depositDayJitaPrice($typeId) * $unmatchedUnits, true, 0, $source, $matches);
    }

    private function requireLaunchPrice(IndustryProjectBomLine $bomItem): float
    {
        return $bomItem->getLaunchPrice()
            ?? throw new \DomainException(sprintf('No launch price is frozen for %s: its unclaimed stock cannot be valued.', $bomItem->getTypeName()));
    }

    private function depositDayJitaPrice(int $typeId): float
    {
        $jitaPrice = $this->jitaMarket->getCheapestPercentilePrices([$typeId], self::DEPOSIT_DAY_PERCENTILE)[$typeId] ?? null;

        if ($jitaPrice === null || $jitaPrice <= 0) {
            throw new \DomainException(sprintf('No market price for type %d: no Jita price for the deposit day.', $typeId));
        }

        return $jitaPrice;
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

    /**
     * @throws \DomainException if contribution is not pending
     */
    public function confirmDelivery(IndustryProjectContribution $contribution): void
    {
        if ($contribution->getStatus() !== ContributionStatus::Pending) {
            throw new \DomainException(
                sprintf('Cannot confirm the delivery of a contribution with status "%s"', $contribution->getStatus()->value),
            );
        }

        $now = new \DateTimeImmutable();
        $contribution->setStatus(ContributionStatus::Approved);
        $contribution->setReviewedBy(null);
        $contribution->setReviewedAt($now);
        $contribution->setIsVerified(true);
        $contribution->setVerifiedAt($now);

        $bomItem = $contribution->getBomItem();
        if ($bomItem !== null) {
            $this->incrementFulfillment($bomItem, $contribution->getQuantity());
            $this->lineReservations->settleApproval($bomItem, $contribution->getMember(), $contribution->getQuantity());
        }
    }

    private function incrementFulfillment(IndustryProjectBomLine $bomItem, int $quantity): void
    {
        $bomItem->setFulfilledQuantity($bomItem->getFulfilledQuantity() + $quantity);
    }
}
