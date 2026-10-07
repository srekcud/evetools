<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProject>
 */
class IndustryProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProject::class);
    }

    /**
     * Loads the user's projects with their steps and the steps' job matches in two queries,
     * so that listing projects does not lazy-load each collection one by one.
     * Job matches are fetched separately: fetch-joining both collections in one query
     * would multiply the rows (steps x job matches).
     *
     * @return IndustryProject[]
     */
    public function findByUserWithStepsAndJobMatches(User $user): array
    {
        /** @var IndustryProject[] $projects */
        $projects = $this->createQueryBuilder('p')
            ->leftJoin('p.steps', 's')
            ->addSelect('s')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        if ($projects === []) {
            return [];
        }

        // Hydrates the jobMatches collection of the steps already in the identity map
        $this->getEntityManager()->createQueryBuilder()
            ->select('s', 'jm')
            ->from(IndustryProjectStep::class, 's')
            ->leftJoin('s.jobMatches', 'jm')
            ->where('s.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->getQuery()
            ->getResult();

        return $projects;
    }
}
