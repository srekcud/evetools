<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryStructureConfig>
 */
class IndustryStructureConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryStructureConfig::class);
    }

    /**
     * @return IndustryStructureConfig[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.isDefault', 'DESC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefaultForUser(User $user): ?IndustryStructureConfig
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.isDefault = true')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function clearDefaultForUser(User $user): void
    {
        $this->createQueryBuilder('s')
            ->update()
            ->set('s.isDefault', 'false')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
