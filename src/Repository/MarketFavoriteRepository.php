<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketFavorite>
 */
class MarketFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketFavorite::class);
    }

    /**
     * @return MarketFavorite[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndType(User $user, int $typeId): ?MarketFavorite
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->andWhere('f.typeId = :typeId')
            ->setParameter('user', $user)
            ->setParameter('typeId', $typeId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return int[]
     */
    public function findTypeIdsByUser(User $user): array
    {
        /** @var list<int|string> $results */
        $results = $this->createQueryBuilder('f')
            ->select('f.typeId')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $results);
    }
}
