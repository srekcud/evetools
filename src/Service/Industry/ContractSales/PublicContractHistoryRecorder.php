<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;
use App\Repository\PublicContractListingRepository;
use Doctrine\ORM\EntityManagerInterface;

class PublicContractHistoryRecorder
{
    public const int RETENTION_DAYS = 90;
    private const int LOOKUP_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly PublicContractListingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<ObservedContract> $readableContracts
     * @param list<int> $listedContractIds
     */
    public function record(int $regionId, array $readableContracts, array $listedContractIds, \DateTimeImmutable $now): void
    {
        $listedIds = array_flip($listedContractIds);

        $tracked = [];
        foreach ($this->repository->findUnconcludedInRegion($regionId) as $listing) {
            $tracked[$listing->getContractId()] = $listing;
            if (isset($listedIds[$listing->getContractId()])) {
                $listing->seenAgain($now);
            } else {
                $listing->missed($now);
            }
        }

        $untracked = array_filter(
            $readableContracts,
            static fn (ObservedContract $contract): bool => !isset($tracked[$contract->contractId]),
        );
        $concluded = $this->concludedListingsAmong($untracked);

        foreach ($untracked as $contract) {
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
    }

    public function purgeExpiredHistory(\DateTimeImmutable $now): int
    {
        return $this->repository->purgeLastSeenBefore($now->modify('-' . self::RETENTION_DAYS . ' days'));
    }

    /**
     * @param array<int, ObservedContract> $contracts
     * @return array<int, PublicContractListing>
     */
    private function concludedListingsAmong(array $contracts): array
    {
        $concluded = [];
        $contractIds = array_map(static fn (ObservedContract $contract): int => $contract->contractId, array_values($contracts));
        foreach (array_chunk($contractIds, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            foreach ($this->repository->findByContractIds($chunk) as $listing) {
                $concluded[$listing->getContractId()] = $listing;
            }
        }

        return $concluded;
    }
}
