<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\User;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSiteStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MiningSite>
 */
class MiningSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningSite::class);
    }

    /**
     * @return list<MiningSite>
     */
    public function findOwnedBy(User $owner, ?int $solarSystemId): array
    {
        $criteria = ['owner' => $owner];
        if ($solarSystemId !== null) {
            $criteria['solarSystemId'] = $solarSystemId;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * @return list<MiningSite>
     */
    public function findClosedLinkedToAnOperationOwnedBy(User $owner): array
    {
        return $this->createQueryBuilder('site')
            ->andWhere('site.owner = :owner')
            ->andWhere('site.operation IS NOT NULL')
            ->andWhere('site.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', MiningSiteStatus::Closed)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MiningSite>
     */
    public function findActiveOfOperation(MiningOperation $operation): array
    {
        return $this->findBy(['operation' => $operation, 'status' => MiningSiteStatus::Active], ['createdAt' => 'ASC']);
    }

    /**
     * @return list<MiningSite>
     */
    public function findActiveOfRunningOperations(): array
    {
        return $this->createQueryBuilder('site')
            ->innerJoin('site.operation', 'operation')
            ->andWhere('site.status = :siteStatus')
            ->andWhere('operation.status = :operationStatus')
            ->setParameter('siteStatus', MiningSiteStatus::Active)
            ->setParameter('operationStatus', MiningOperationStatus::InProgress)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MiningSite>
     */
    public function findOfOperation(MiningOperation $operation, ?int $solarSystemId): array
    {
        $criteria = ['operation' => $operation];
        if ($solarSystemId !== null) {
            $criteria['solarSystemId'] = $solarSystemId;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * @param list<MiningOperation> $operations
     *
     * @return list<MiningSite>
     */
    public function findOfOperations(array $operations): array
    {
        if ($operations === []) {
            return [];
        }

        return $this->createQueryBuilder('site')
            ->where('site.operation IN (:operations)')
            ->setParameter('operations', $operations)
            ->orderBy('site.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
