<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Repository\CachedWalletTransactionRepository;

class ContributorPaidPurchaseReader
{
    public function __construct(
        private readonly CachedWalletTransactionRepository $walletTransactions,
    ) {
    }

    public function read(IndustryProjectParticipant $member, int $typeId): ?PaidMaterialPurchases
    {
        $user = $member->getUser();
        if (null === $user) {
            return null;
        }

        $project = $member->getProject();
        $linkedQuantities = $this->linkedQuantitiesByTransaction($project);

        $transactions = [];
        foreach ($user->getCharacters() as $character) {
            foreach ($this->walletTransactions->findBuysByTypeIds($character, [$typeId], $project->getCreatedAt()) as $transaction) {
                $transactions[] = $transaction;
            }
        }
        usort($transactions, static fn (CachedWalletTransaction $first, CachedWalletTransaction $second): int => $first->getDate() <=> $second->getDate());

        $units = [];
        foreach ($transactions as $transaction) {
            $remaining = $transaction->getQuantity() - ($linkedQuantities[spl_object_id($transaction)] ?? 0);
            if ($remaining > 0) {
                $units[] = new PaidTransactionUnits($transaction, $remaining);
            }
        }

        return [] === $units ? null : new PaidMaterialPurchases($units);
    }

    /**
     * @return array<int, int>
     */
    private function linkedQuantitiesByTransaction(IndustryProject $project): array
    {
        $linked = [];
        foreach ($project->getBomItems() as $line) {
            foreach ($line->getPurchases() as $purchase) {
                $transaction = $purchase->getTransaction();
                if (null !== $transaction) {
                    $linked[spl_object_id($transaction)] = ($linked[spl_object_id($transaction)] ?? 0) + $purchase->getQuantity();
                }
            }
        }

        return $linked;
    }
}
