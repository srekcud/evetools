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

        usort($transactions, static fn (CachedWalletTransaction $first, CachedWalletTransaction $second): int => $first->getDate() <=> $second->getDate());

        $linkedCount = 0;
        foreach ($transactions as $transaction) {
            if (!$transaction->isBuy() || $this->purchaseRepository->findOneBy(['transaction' => $transaction]) !== null) {
                continue;
            }

            $candidateLines = $this->candidateLinesOf($openProjects, $transaction);
            if (\count($candidateLines) !== 1 || $transaction->getQuantity() > $candidateLines[0]->getRemainingQuantity()) {
                continue;
            }

            $purchase = ProjectPurchaseFactory::fromTransaction($transaction, null);
            $candidateLines[0]->addPurchase($purchase);
            $this->entityManager->persist($purchase);
            $this->derivedContributionService->refreshLine($candidateLines[0]);
            ++$linkedCount;
        }

        if ($linkedCount > 0) {
            $this->entityManager->flush();
        }
    }

    /**
     * @param list<IndustryProject> $openProjects
     *
     * @return list<IndustryProjectBomLine>
     */
    private function candidateLinesOf(array $openProjects, CachedWalletTransaction $transaction): array
    {
        $candidateLines = [];
        foreach ($openProjects as $project) {
            if ($transaction->getDate() < $project->getCreatedAt()) {
                continue;
            }

            foreach ($project->getBomItems() as $line) {
                if ($line->isPurchasable() && $line->getTypeId() === $transaction->getTypeId() && $line->getRemainingQuantity() > 0) {
                    $candidateLines[] = $line;
                }
            }
        }

        return $candidateLines;
    }
}
