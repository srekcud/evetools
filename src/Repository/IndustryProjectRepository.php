<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     * Loads the owner's projects planned by steps with their steps and the steps' job matches in two queries,
     * so that listing projects does not lazy-load each collection one by one.
     * Job matches are fetched separately: fetch-joining both collections in one query
     * would multiply the rows (steps x job matches).
     *
     * @return IndustryProject[]
     */
    public function findByOwnerWithStepsAndJobMatches(User $owner): array
    {
        /** @var IndustryProject[] $projects */
        $projects = $this->createQueryBuilder('p')
            ->leftJoin('p.steps', 's')
            ->addSelect('s')
            ->where('p.owner = :owner')
            ->andWhere('p.items IS EMPTY')
            ->setParameter('owner', $owner)
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

    /**
     * @return list<Uuid>
     */
    public function findIdsInCreationOrder(): array
    {
        $rawIds = $this->createQueryBuilder('p')
            ->select('p.id')
            ->orderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), $rawIds));
    }

    /**
     * Find projects with items owned by users in a given corporation, filtered by status.
     *
     * @param GroupProjectStatus[] $statuses
     * @return IndustryProject[]
     */
    public function findByOwnerCorporation(int $corporationId, array $statuses): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('owner', 'mc', 'ownerToken')
            ->join('p.owner', 'owner')
            ->join('owner.mainCharacter', 'mc')
            // Character::eveToken is an inverse OneToOne: not joined, Doctrine would load it row by row
            ->leftJoin('mc.eveToken', 'ownerToken')
            ->where('mc.corporationId = :corpId')
            ->andWhere('p.items IS NOT EMPTY')
            ->setParameter('corpId', $corporationId);

        if (!empty($statuses)) {
            $qb->andWhere('p.status IN (:statuses)')
                ->setParameter('statuses', array_map(fn (GroupProjectStatus $s) => $s->value, $statuses));
        }

        $qb->orderBy('p.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function findByShortLinkCode(string $code): ?IndustryProject
    {
        return $this->createQueryBuilder('p')
            ->where('p.shortLinkCode = :code')
            ->andWhere('p.items IS NOT EMPTY')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
