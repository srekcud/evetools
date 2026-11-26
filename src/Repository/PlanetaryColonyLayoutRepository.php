<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanetaryColonyLayout;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanetaryColonyLayout>
 */
class PlanetaryColonyLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetaryColonyLayout::class);
    }

    /**
     * @return list<PlanetaryColonyLayout>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('layout')
            ->where('layout.user = :user')
            ->setParameter('user', $user)
            ->orderBy('layout.createdAt', 'DESC')
            ->addOrderBy('layout.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, Uuid $id): ?PlanetaryColonyLayout
    {
        return $this->createQueryBuilder('layout')
            ->where('layout.user = :user')
            ->andWhere('layout.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('layout')
            ->select('COUNT(layout.id)')
            ->where('layout.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
