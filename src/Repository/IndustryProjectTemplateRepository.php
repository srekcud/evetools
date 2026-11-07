<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectTemplate>
 */
class IndustryProjectTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectTemplate::class);
    }

    /** @return list<IndustryProjectTemplate> */
    public function findVisibleTo(User $user): array
    {
        throw new \LogicException('not implemented');
    }

    public function countByOwner(User $owner): int
    {
        throw new \LogicException('not implemented');
    }
}
