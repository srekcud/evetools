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
        $alreadyLinked = $this->transactionsLinkedTo($project);

        $quantity = 0;
        $totalPrice = 0.0;
        foreach ($user->getCharacters() as $character) {
            foreach ($this->walletTransactions->findBuysByTypeIds($character, [$typeId], $project->getCreatedAt()) as $transaction) {
                if (\in_array($transaction, $alreadyLinked, true)) {
                    continue;
                }

                $quantity += $transaction->getQuantity();
                $totalPrice += $transaction->getTotalPrice();
            }
        }

        return 0 === $quantity ? null : new PaidMaterialPurchases($quantity, $totalPrice);
    }

    /**
     * @return list<CachedWalletTransaction>
     */
    private function transactionsLinkedTo(IndustryProject $project): array
    {
        $linked = [];
        foreach ($project->getBomItems() as $line) {
            foreach ($line->getPurchases() as $purchase) {
                $transaction = $purchase->getTransaction();
                if (null !== $transaction) {
                    $linked[] = $transaction;
                }
            }
        }

        return $linked;
    }
}
