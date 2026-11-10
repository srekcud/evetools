<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporationWalletDonation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporationWalletDonation>
 */
class CorporationWalletDonationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporationWalletDonation::class);
    }

    /**
     * @return list<CorporationWalletDonation>
     */
    public function findOfCorporation(int $corporationId): array
    {
        return $this->findBy(['corporationId' => $corporationId], ['date' => 'ASC']);
    }

    /**
     * @return list<CorporationWalletDonation>
     */
    public function findOfCorporationSince(int $corporationId, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('donation')
            ->where('donation.corporationId = :corporationId')
            ->andWhere('donation.date >= :since')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('since', $since)
            ->orderBy('donation.date', 'DESC')
            ->addOrderBy('donation.journalId', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $characterIds
     *
     * @return list<CorporationWalletDonation>
     */
    public function findForPayers(array $characterIds): array
    {
        return $this->createQueryBuilder('donation')
            ->where('donation.attributedCharacterId IN (:characterIds)')
            ->orWhere('donation.attributedCharacterId IS NULL AND donation.firstPartyId IN (:characterIds)')
            ->setParameter('characterIds', $characterIds)
            ->orderBy('donation.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $journalIds
     *
     * @return array<int, CorporationWalletDonation> keyed by journal id
     */
    public function findByJournalIds(int $corporationId, array $journalIds): array
    {
        $donations = [];
        foreach ($this->findBy(['corporationId' => $corporationId, 'journalId' => $journalIds]) as $donation) {
            $donations[$donation->getJournalId()] = $donation;
        }

        return $donations;
    }
}
