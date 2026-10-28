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

    /**
     * @return GroupIndustrySale[]
     */
    public function findByProjectForListing(GroupIndustryProject $project): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('recorder', 'recorderCharacter', 'recorderToken')
            ->leftJoin('s.recordedBy', 'recorder')
            ->leftJoin('recorder.mainCharacter', 'recorderCharacter')
            ->leftJoin('recorderCharacter.eveToken', 'recorderToken')
            ->andWhere('s.project = :project')
            ->setParameter('project', $project)
            ->orderBy('s.soldAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
