<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryProjectItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupIndustryProjectItem>
 */
class GroupIndustryProjectItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupIndustryProjectItem::class);
    }
}
