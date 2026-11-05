<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryBomItem;
use App\Entity\IndustryProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupIndustryBomItem>
 */
class GroupIndustryBomItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupIndustryBomItem::class);
    }

    /**
     * @return GroupIndustryBomItem[]
     */
    public function findMaterialsByProject(IndustryProject $project): array
    {
        return $this->findBy(['project' => $project, 'isJob' => false]);
    }
}
