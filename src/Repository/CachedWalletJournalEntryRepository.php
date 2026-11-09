<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedWalletJournalEntry>
 */
class CachedWalletJournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedWalletJournalEntry::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function findExistingEntryIds(Character $character, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('entry')
            ->select('entry.journalEntryId')
            ->andWhere('entry.character = :character')
            ->andWhere('entry.journalEntryId IN (:entryIds)')
            ->setParameter('character', $character)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): int => (int) $row['journalEntryId'], $rows));
    }

    /**
     * @param list<int> $contractIds
     *
     * @return array<int, float> fees paid per contract, only for the contracts the journal has a fee line for
     */
    public function contractFeesByContractId(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('entry')
            ->select('entry.contextId AS contractId', 'SUM(entry.amount) AS total')
            ->andWhere('entry.contextIdType = :contextType')
            ->andWhere('entry.refType IN (:refTypes)')
            ->andWhere('entry.contextId IN (:contractIds)')
            ->groupBy('entry.contextId')
            ->setParameter('contextType', CachedWalletJournalEntry::CONTRACT_CONTEXT_TYPE)
            ->setParameter('refTypes', CachedWalletJournalEntry::CONTRACT_FEE_REF_TYPES)
            ->setParameter('contractIds', $contractIds)
            ->getQuery()
            ->getScalarResult();

        $fees = [];
        foreach ($rows as $row) {
            $fees[(int) $row['contractId']] = -(float) $row['total'];
        }

        return $fees;
    }
}
