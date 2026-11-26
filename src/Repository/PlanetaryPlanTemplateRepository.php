<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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
        return [];
    }

    public function findOneByUserAndId(User $user, Uuid $id): ?PlanetaryPlanTemplate
    {
        return null;
    }

    public function countByUser(User $user): int
    {
        return 0;
    }
}
