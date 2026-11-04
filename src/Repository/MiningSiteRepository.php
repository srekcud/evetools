<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningSite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    public function findOwnedById(User $owner, Uuid $id): ?MiningSite
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }
}
