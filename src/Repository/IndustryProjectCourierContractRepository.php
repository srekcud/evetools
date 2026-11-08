<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectCourierContract;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectCourierContract>
 */
class IndustryProjectCourierContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectCourierContract::class);
    }

    public function findOneByProject(IndustryProject $project): ?IndustryProjectCourierContract
    {
        return $this->findOneBy(['project' => $project]);
    }

    /**
     * @return list<IndustryProjectCourierContract>
     */
    public function findPendingFailureCheck(): array
    {
        return $this->createQueryBuilder('link')
            ->join('link.project', 'project')
            ->andWhere('link.failureNotifiedAt IS NULL')
            ->andWhere('project.status != :completed')
            ->setParameter('completed', ProjectStatus::Completed)
            ->getQuery()
            ->getResult();
    }
}
