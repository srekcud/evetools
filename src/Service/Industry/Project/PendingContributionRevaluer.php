<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectContribution;
use App\Entity\User;
use App\Enum\ContributionValueSource;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;

class PendingContributionRevaluer
{
    private const string CONTRIBUTION_VALUED_ACTION = 'contribution_valued';

    public function __construct(
        private readonly IndustryProjectContributionRepository $contributions,
        private readonly ContributorPaidPurchaseReader $paidPurchases,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function revalueFor(User $user): void
    {
        $revalued = array_values(array_filter(
            $this->contributions->findPendingRevaluableOf($user),
            fn (IndustryProjectContribution $contribution): bool => $this->revalue($contribution),
        ));
        if ([] === $revalued) {
            return;
        }

        $this->entityManager->flush();

        foreach ($revalued as $contribution) {
            $this->mercurePublisher->publishGroupProjectEvent(
                $contribution->getProject()->getId()->toRfc4122(),
                self::CONTRIBUTION_VALUED_ACTION,
                [
                    'contributionId' => $contribution->getId()->toRfc4122(),
                    'bomItemId' => $contribution->getBomItem()?->getId()?->toRfc4122(),
                ],
            );
        }
    }

    private function revalue(IndustryProjectContribution $contribution): bool
    {
        $line = $contribution->getBomItem();
        if (null === $line) {
            return false;
        }

        $linkedUnits = 0;
        $linkedValue = 0.0;
        foreach ($line->getPurchases() as $purchase) {
            if ($purchase->getContribution() === $contribution) {
                $linkedUnits += $purchase->getQuantity();
                $linkedValue += $purchase->getTotalPrice();
            }
        }

        $missingUnits = $contribution->getQuantity() - $linkedUnits;
        $paid = $missingUnits > 0 ? $this->paidPurchases->read($contribution->getMember(), $line->getTypeId()) : null;
        $matches = null === $paid ? [] : $paid->allocate($missingUnits);
        if ([] === $matches) {
            return false;
        }

        $newUnits = array_sum(array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $matches));
        $newValue = (float) array_sum(array_map(static fn (PaidTransactionUnits $units): float => $units->totalPrice(), $matches));
        $remainingUnits = $missingUnits - $newUnits;

        if (ContributionValueSource::WalletPurchase !== $contribution->getValueSource()) {
            $valueBefore = $contribution->getEstimatedValue();
            if (null === $valueBefore) {
                return false;
            }

            $contribution->setEstimatedValue($linkedValue + $newValue + (($valueBefore - $linkedValue) / $missingUnits) * $remainingUnits);
            $contribution->setValuedAtJitaPrice($remainingUnits > 0);
        } else {
            $contribution->setEstimatedValue($linkedValue + $newValue);
            $contribution->setAwaitingQuantity($remainingUnits);
        }

        foreach ($matches as $units) {
            $purchase = ProjectPurchaseFactory::fromUnits($units, $contribution->getMember(), $contribution);
            $line->addPurchase($purchase);
            $this->entityManager->persist($purchase);
        }

        return true;
    }
}
