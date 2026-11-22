<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /**
     * @return IndustryProjectBomLine[]
     */
    public function findJobLinesWithMatchesByProject(IndustryProject $project): array
    {
        return $this->createQueryBuilder('line')
            ->addSelect('jobMatch')
            ->leftJoin('line.jobMatches', 'jobMatch')
            ->andWhere('line.project = :project')
            ->andWhere('line.isJob = true')
            ->andWhere('line.jobGroup IN (:jobGroups)')
            ->setParameter('project', $project)
            ->setParameter('jobGroups', ['component', 'final'])
            ->orderBy('line.id', 'ASC')
            ->addOrderBy('jobMatch.esiJobId', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * @return list<string>
     */
    public function findPlannedStructureIdsByProject(IndustryProject $project): array
    {
        $rows = $this->createQueryBuilder('line')
            ->select('DISTINCT line.plannedStructureId AS plannedStructureId')
            ->andWhere('line.project = :project')
            ->andWhere('line.plannedStructureId IS NOT NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($rows, static fn (string $plannedStructureId): bool => Uuid::isValid($plannedStructureId)));
    }
}
