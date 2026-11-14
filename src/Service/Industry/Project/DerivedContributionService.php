<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Service\Industry\Project\Courier\CourierContractState;
use App\Service\Industry\Project\Courier\CourierLinkedContract;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;

class DerivedContributionService
{
    private const string CONTRIBUTION_SYNCED_ACTION = 'contribution_synced';
    private const int COURIER_QUANTITY = 1;
    private const string REPLACED_NOTE = 'Replaced by the ESI/wallet contribution';
    private const string DECLARED_NOTE_TEMPLATE = '%s. Declared note: %s';

    private readonly DuplicateContributionNotifier $duplicateNotifier;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        NotificationDispatcher $notificationDispatcher,
    ) {
        $this->duplicateNotifier = new DuplicateContributionNotifier($notificationDispatcher);
    }

    public function refreshLine(IndustryProjectBomLine $line): void
    {
        $project = $line->getProject();
        if ($project->isCompleted()) {
            return;
        }

        $ownerParticipant = $this->ownerParticipantOf($project);

        $jobMatchesByInstaller = $this->jobMatchesByInstaller($line, $ownerParticipant);

        $syncedContributionIds = [];
        foreach ($project->getContributions()->toArray() as $contribution) {
            if ($this->isDerivedOn($contribution, $line) && $contribution->getMember() !== $ownerParticipant && !$this->isKeptJobInstall($contribution, $jobMatchesByInstaller)) {
                $syncedContributionIds[] = $this->remove($line, $contribution);
            }
        }

        $syncedContributionIds[] = $this->synchronize($line, $ownerParticipant, ContributionType::Material, $line->getPurchasedQuantity(), $this->paidPriceOf($line));
        foreach ($jobMatchesByInstaller as $installer) {
            $syncedContributionIds[] = $this->synchronize(
                $line,
                $installer['participant'],
                ContributionType::JobInstall,
                array_sum(array_map(static fn (IndustryProjectJobMatch $match): int => $match->getRuns(), $installer['matches'])),
                $this->installCostOf($installer['matches']),
            );
        }

        $syncedContributionIds = array_filter($syncedContributionIds, static fn (?string $contributionId): bool => $contributionId !== null);
        if ($syncedContributionIds === []) {
            return;
        }

        $this->entityManager->flush();

        foreach ($syncedContributionIds as $contributionId) {
            $this->mercurePublisher->publishGroupProjectEvent(
                $project->getId()->toRfc4122(),
                self::CONTRIBUTION_SYNCED_ACTION,
                ['contributionId' => $contributionId, 'bomItemId' => $line->getId()->toRfc4122()],
            );
        }
    }

    public function syncCourier(IndustryProject $project, ?CourierLinkedContract $contract): void
    {
        if ($project->isCompleted() || CourierContractState::Unknown === $contract?->state) {
            return;
        }

        $existing = $this->courierContributionOf($project);
        $contributionId = null !== $contract && CourierContractState::Delivered === $contract->state
            ? $this->countCourierReward($project, $existing, $contract->reward)
            : $this->uncountCourierReward($project, $existing);
        if (null === $contributionId) {
            return;
        }

        $this->entityManager->flush();
        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            self::CONTRIBUTION_SYNCED_ACTION,
            ['contributionId' => $contributionId, 'bomItemId' => null],
        );
    }

    private function synchronize(
        IndustryProjectBomLine $line,
        IndustryProjectParticipant $contributingParticipant,
        ContributionType $type,
        int $quantity,
        ?float $value,
    ): ?string {
        $existing = $this->derivedContributionOf($line, $contributingParticipant, $type);

        if ($quantity === 0) {
            return $existing === null ? null : $this->remove($line, $existing);
        }

        if ($existing === null) {
            return $this->create($line, $contributingParticipant, $type, $quantity, $value);
        }

        if ($existing->getQuantity() === $quantity && $existing->getEstimatedValue() === $value) {
            return null;
        }

        $this->shiftFulfillment($line, $existing, $quantity - $existing->getQuantity());
        $existing->setQuantity($quantity);
        $existing->setEstimatedValue($value);

        return $existing->getId()->toRfc4122();
    }

    private function create(
        IndustryProjectBomLine $line,
        IndustryProjectParticipant $contributingParticipant,
        ContributionType $type,
        int $quantity,
        ?float $value,
    ): string {
        $contribution = $this->derivedContribution($line->getProject(), $contributingParticipant, $type, $quantity, $value, $line)->setBomItem($line);
        $hasApprovedDeclared = $this->hasApprovedDeclared($line, $contributingParticipant, $type);
        $hasRejectedPendingDeclared = $this->rejectPendingDeclared($line, $contributingParticipant, $type);
        $this->persistDerived($contribution);
        $this->shiftFulfillment($line, $contribution, $quantity);
        if ($hasApprovedDeclared || $hasRejectedPendingDeclared) {
            $this->duplicateNotifier->notifyReviewers($contribution);
        }

        return $contribution->getId()->toRfc4122();
    }

    private function rejectPendingDeclared(IndustryProjectBomLine $line, IndustryProjectParticipant $participant, ContributionType $type): bool
    {
        $project = $line->getProject();
        $now = new \DateTimeImmutable();
        $rejected = false;
        foreach ($this->declaredOf($line, $participant, $type) as $declared) {
            if (ContributionStatus::Pending !== $declared->getStatus()) {
                continue;
            }

            $declaredNote = $declared->getNote();
            $declared
                ->setStatus(ContributionStatus::Rejected)
                ->setReviewedBy($project->getOwner())
                ->setReviewedAt($now)
                ->setNote(null === $declaredNote || '' === $declaredNote
                    ? self::REPLACED_NOTE
                    : sprintf(self::DECLARED_NOTE_TEMPLATE, self::REPLACED_NOTE, $declaredNote));
            $rejected = true;
        }

        return $rejected;
    }

    private function hasApprovedDeclared(IndustryProjectBomLine $line, IndustryProjectParticipant $participant, ContributionType $type): bool
    {
        foreach ($this->declaredOf($line, $participant, $type) as $declared) {
            if (ContributionStatus::Approved === $declared->getStatus()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<IndustryProjectContribution>
     */
    private function declaredOf(IndustryProjectBomLine $line, IndustryProjectParticipant $participant, ContributionType $type): array
    {
        return array_values(array_filter(
            $line->getProject()->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => !$contribution->isAutoDetected()
                && $contribution->getBomItem() === $line
                && $contribution->getMember() === $participant
                && $contribution->getType() === $type,
        ));
    }

    private function countCourierReward(IndustryProject $project, ?IndustryProjectContribution $existing, ?float $reward): ?string
    {
        if (null === $existing) {
            $contribution = $this->derivedContribution($project, $this->ownerParticipantOf($project), ContributionType::Courier, self::COURIER_QUANTITY, $reward);
            $this->persistDerived($contribution);

            return $contribution->getId()->toRfc4122();
        }

        if ($existing->getEstimatedValue() === $reward) {
            return null;
        }

        $existing->setEstimatedValue($reward);

        return $existing->getId()->toRfc4122();
    }

    private function uncountCourierReward(IndustryProject $project, ?IndustryProjectContribution $existing): ?string
    {
        if (null === $existing) {
            return null;
        }

        $project->getContributions()->removeElement($existing);
        $this->entityManager->remove($existing);

        return $existing->getId()->toRfc4122();
    }

    private function derivedContribution(
        IndustryProject $project,
        IndustryProjectParticipant $contributingParticipant,
        ContributionType $type,
        int $quantity,
        ?float $value,
        ?IndustryProjectBomLine $line = null,
    ): IndustryProjectContribution {
        $contribution = (new IndustryProjectContribution())
            ->setProject($project)
            ->setMember($contributingParticipant)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue($value)
            ->setIsAutoDetected(true);

        if ($this->isProposalOnly($project, $type, $line)) {
            return $contribution->setStatus(ContributionStatus::Pending)->setIsVerified(false);
        }

        $now = new \DateTimeImmutable();

        return $contribution
            ->setStatus(ContributionStatus::Approved)
            ->setIsVerified(true)
            ->setVerifiedAt($now)
            ->setReviewedBy($contributingParticipant->getUser())
            ->setReviewedAt($now);
    }

    private function isProposalOnly(IndustryProject $project, ContributionType $type, ?IndustryProjectBomLine $line): bool
    {
        return ContributionType::Material === $type && null !== $line && !$line->isJob() && !$project->isSoleReviewingOwner($project->getOwner());
    }

    private function persistDerived(IndustryProjectContribution $contribution): void
    {
        $contribution->getProject()->addContribution($contribution);
        $this->entityManager->persist($contribution);
    }

    private function remove(IndustryProjectBomLine $line, IndustryProjectContribution $contribution): string
    {
        $this->shiftFulfillment($line, $contribution, -$contribution->getQuantity());
        $line->getProject()->getContributions()->removeElement($contribution);
        $this->entityManager->remove($contribution);

        return $contribution->getId()->toRfc4122();
    }

    private function shiftFulfillment(IndustryProjectBomLine $line, IndustryProjectContribution $contribution, int $quantityDifference): void
    {
        if ($contribution->getType() !== ContributionType::Material || $contribution->getStatus() !== ContributionStatus::Approved) {
            return;
        }

        $line->setFulfilledQuantity($line->getFulfilledQuantity() + $quantityDifference);
    }

    private function paidPriceOf(IndustryProjectBomLine $line): float
    {
        $paidPrice = 0.0;
        foreach ($line->getPurchases() as $purchase) {
            if ($purchase->getTypeId() === $line->getTypeId()) {
                $paidPrice += $purchase->getTotalPrice();
            }
        }

        return $paidPrice;
    }

    /**
     * @param list<IndustryProjectJobMatch> $jobMatches
     */
    private function installCostOf(array $jobMatches): ?float
    {
        $installCost = 0.0;
        foreach ($jobMatches as $jobMatch) {
            $cost = $jobMatch->getCost();
            if ($cost === null) {
                return null;
            }
            $installCost += $cost;
        }

        return $installCost;
    }

    /**
     * @return array<int, array{participant: IndustryProjectParticipant, matches: list<IndustryProjectJobMatch>}>
     */
    private function jobMatchesByInstaller(IndustryProjectBomLine $line, IndustryProjectParticipant $ownerParticipant): array
    {
        $byInstaller = [spl_object_id($ownerParticipant) => ['participant' => $ownerParticipant, 'matches' => []]];
        foreach ($line->getJobMatches() as $jobMatch) {
            $installer = $this->participantInstalling($line->getProject(), $jobMatch, $ownerParticipant);
            $byInstaller[spl_object_id($installer)] ??= ['participant' => $installer, 'matches' => []];
            $byInstaller[spl_object_id($installer)]['matches'][] = $jobMatch;
        }

        return $byInstaller;
    }

    private function participantInstalling(IndustryProject $project, IndustryProjectJobMatch $jobMatch, IndustryProjectParticipant $ownerParticipant): IndustryProjectParticipant
    {
        $installer = ProjectJobInstaller::userOf($project, $jobMatch->getCharacterName());
        foreach ($project->getMembers() as $member) {
            if ($member->getUser() === $installer) {
                return $member;
            }
        }

        return $ownerParticipant;
    }

    /**
     * @param array<int, array{participant: IndustryProjectParticipant, matches: list<IndustryProjectJobMatch>}> $jobMatchesByInstaller
     */
    private function isKeptJobInstall(IndustryProjectContribution $contribution, array $jobMatchesByInstaller): bool
    {
        $member = $contribution->getMember();

        return ContributionType::JobInstall === $contribution->getType()
            && isset($jobMatchesByInstaller[spl_object_id($member)])
            && $jobMatchesByInstaller[spl_object_id($member)]['matches'] !== [];
    }

    private function ownerParticipantOf(IndustryProject $project): IndustryProjectParticipant
    {
        foreach ($project->getMembers() as $member) {
            if ($member->getUser() === $project->getOwner()) {
                return $member;
            }
        }

        throw new \LogicException(sprintf('Project %s has no participant for its owner.', $project->getId()->toRfc4122()));
    }

    private function isDerivedOn(IndustryProjectContribution $contribution, IndustryProjectBomLine $line): bool
    {
        return $contribution->isAutoDetected() && $contribution->getBomItem() === $line;
    }

    private function courierContributionOf(IndustryProject $project): ?IndustryProjectContribution
    {
        foreach ($project->getContributions() as $contribution) {
            if ($contribution->isAutoDetected() && ContributionType::Courier === $contribution->getType() && null === $contribution->getBomItem()) {
                return $contribution;
            }
        }

        return null;
    }

    private function derivedContributionOf(IndustryProjectBomLine $line, IndustryProjectParticipant $contributingParticipant, ContributionType $type): ?IndustryProjectContribution
    {
        foreach ($line->getProject()->getContributions() as $contribution) {
            if ($this->isDerivedOn($contribution, $line) && $contribution->getMember() === $contributingParticipant && $contribution->getType() === $type) {
                return $contribution;
            }
        }

        return null;
    }
}
