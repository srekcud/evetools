<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\User;
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
    public function findActiveOfOperation(MiningOperation $operation): array
    {
        return $this->findBy(['operation' => $operation, 'status' => MiningSiteStatus::Active], ['createdAt' => 'ASC']);
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
}
