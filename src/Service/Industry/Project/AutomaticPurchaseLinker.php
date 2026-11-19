<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

class AutomaticPurchaseLinker
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectPurchaseRepository $purchaseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DerivedContributionService $derivedContributionService,
        private readonly ContainerDeliveryConfirmer $containerDeliveryConfirmer,
    ) {
    }

    /**
     * @param list<CachedWalletTransaction> $transactions
     */
    public function linkNewPurchases(Character $character, array $transactions): void
    {
        $owner = $character->getUser();
        if ($owner === null) {
            return;
        }

        $openProjects = $this->projectRepository->findOpenOwnedBy($owner);
        if ($openProjects === []) {
            return;
        }

        usort($openProjects, static fn (IndustryProject $first, IndustryProject $second): int => $first->getCreatedAt() <=> $second->getCreatedAt());
        usort($transactions, static fn (CachedWalletTransaction $first, CachedWalletTransaction $second): int => $first->getDate() <=> $second->getDate());

        $linkedCount = 0;
        $touchedProjects = [];
        foreach ($transactions as $transaction) {
            if (!$transaction->isBuy() || $this->purchaseRepository->findOneBy(['transaction' => $transaction]) !== null) {
                continue;
            }

            $line = $this->lineToLink($openProjects, $transaction);
            if ($line === null) {
                continue;
            }

            $purchase = ProjectPurchaseFactory::fromTransaction($transaction, null);
            $purchase->setBuyer($line->getProject()->getOwnerParticipant());
            $line->addPurchase($purchase);
            $this->entityManager->persist($purchase);
            $this->derivedContributionService->refreshLine($line);
            $touchedProjects[$line->getProject()->getId()->toRfc4122()] = $line->getProject();
            ++$linkedCount;
        }

        if ($linkedCount > 0) {
            $this->entityManager->flush();
        }

        foreach ($touchedProjects as $project) {
            $this->containerDeliveryConfirmer->confirmProject($project);
        }
    }

    private function quantityLeftToBuy(IndustryProjectBomLine $line): int
    {
        return max(0, $line->getRequiredQuantity() - $line->getPurchasedQuantity());
    }

    /**
     * @param list<IndustryProject> $projectsOldestFirst
     */
    private function lineToLink(array $projectsOldestFirst, CachedWalletTransaction $transaction): ?IndustryProjectBomLine
    {
        foreach ($projectsOldestFirst as $project) {
            $candidateLines = $this->candidateLinesOf($project, $transaction);
            if (!array_any($candidateLines, fn (IndustryProjectBomLine $line): bool => $transaction->getQuantity() <= $this->quantityLeftToBuy($line))) {
                continue;
            }

            return \count($candidateLines) === 1 ? $candidateLines[0] : null;
        }

        return null;
    }

    /**
     * @return list<IndustryProjectBomLine>
     */
    private function candidateLinesOf(IndustryProject $project, CachedWalletTransaction $transaction): array
    {
        if ($transaction->getDate() < $project->getCreatedAt()) {
            return [];
        }

        return array_values(array_filter(
            $project->getBomItems()->toArray(),
            static fn (IndustryProjectBomLine $line): bool => $line->isPurchasable() && $line->getTypeId() === $transaction->getTypeId() && $line->getRemainingQuantity() > 0,
        ));
    }
}
