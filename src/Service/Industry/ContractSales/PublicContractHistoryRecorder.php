<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use Doctrine\ORM\EntityManagerInterface;

class PublicContractHistoryRecorder
{
    public const int RETENTION_DAYS = 90;
    public const int BATCH_SIZE = 1000;

    /**
     * @param int<1, max> $batchSize
     */
    public function __construct(
        private readonly PublicContractListingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $batchSize = self::BATCH_SIZE,
    ) {
    }

    /**
     * @param list<ObservedContract> $readableContracts
     * @param list<int> $listedContractIds
     */
    public function record(int $regionId, array $readableContracts, array $listedContractIds, \DateTimeImmutable $now): void
    {
        $listedIds = array_flip($listedContractIds);
        $trackedContractIds = $this->repository->findUnconcludedContractIdsInRegion($regionId);

        foreach (array_chunk($trackedContractIds, $this->batchSize) as $contractIdBatch) {
            $this->updateTrackedListings($contractIdBatch, $listedIds, $now);
        }

        $trackedIds = array_flip($trackedContractIds);
        $untracked = array_values(array_filter(
            $readableContracts,
            static fn (ObservedContract $contract): bool => !isset($trackedIds[$contract->contractId]),
        ));

        foreach (array_chunk($untracked, $this->batchSize) as $contractBatch) {
            $this->recordUntrackedContracts($regionId, $contractBatch, $now);
        }
    }

    public function purgeExpiredHistory(\DateTimeImmutable $now): int
    {
        return $this->repository->purgeLastSeenBefore($now->modify('-' . self::RETENTION_DAYS . ' days'));
    }

    /**
     * @param list<int> $contractIds
     * @param array<int, int> $listedIds
     */
    private function updateTrackedListings(array $contractIds, array $listedIds, \DateTimeImmutable $now): void
    {
        foreach ($this->repository->findByContractIds($contractIds) as $listing) {
            if (isset($listedIds[$listing->getContractId()])) {
                $listing->seenAgain($now);
            } else {
                $listing->missed($now);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @param list<ObservedContract> $contracts
     */
    private function recordUntrackedContracts(int $regionId, array $contracts, \DateTimeImmutable $now): void
    {
        $concluded = [];
        $contractIds = array_map(static fn (ObservedContract $contract): int => $contract->contractId, $contracts);
        foreach ($this->repository->findByContractIds($contractIds) as $listing) {
            $concluded[$listing->getContractId()] = $listing;
        }

        foreach ($contracts as $contract) {
            if (isset($concluded[$contract->contractId])) {
                $concluded[$contract->contractId]->seenAgain($now);
                continue;
            }

            if ($contract->price <= 0.0 || [] === $contract->items) {
                continue;
            }

            $this->entityManager->persist(PublicContractListing::firstSeen(
                $contract->contractId,
                $regionId,
                $contract->issuerId,
                $contract->price,
                $contract->items,
                $contract->dateExpired,
                $now,
            ));
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
