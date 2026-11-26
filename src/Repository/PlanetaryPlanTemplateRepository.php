<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanetaryPlanTemplate>
 */
class PlanetaryPlanTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetaryPlanTemplate::class);
    }

    /**
     * @return list<PlanetaryPlanTemplate>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('template')
            ->where('template.user = :user')
            ->setParameter('user', $user)
            ->orderBy('template.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, Uuid $id): ?PlanetaryPlanTemplate
    {
        return $this->createQueryBuilder('template')
            ->where('template.user = :user')
            ->andWhere('template.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('template')
            ->select('COUNT(template.id)')
            ->where('template.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
