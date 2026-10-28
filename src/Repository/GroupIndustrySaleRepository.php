<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustrySale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupIndustrySale>
 */
class GroupIndustrySaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupIndustrySale::class);
    }

    public function findByProjectForListing(GroupIndustryProject $project): array
    {
        throw new \LogicException('not implemented');
    }
}
