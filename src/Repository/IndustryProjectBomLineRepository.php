<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectBomLine>
 */
class IndustryProjectBomLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectBomLine::class);
    }

    /**
     * @return IndustryProjectBomLine[]
     */
    public function findMaterialsByProject(IndustryProject $project): array
    {
        return $this->findBy(['project' => $project, 'isJob' => false]);
    }

    /**
     * @return IndustryProjectBomLine[]
     */
    public function findComponentJobsByProject(IndustryProject $project): array
    {
        return $this->findBy(['project' => $project, 'isJob' => true, 'jobGroup' => 'component']);
    }

    public function loadJobMatchesOfProject(IndustryProject $project): void
    {
        $this->createQueryBuilder('line')
            ->addSelect('jobMatch')
            ->leftJoin('line.jobMatches', 'jobMatch')
            ->andWhere('line.project = :project')
            ->setParameter('project', $project)
            ->orderBy('jobMatch.esiJobId', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
