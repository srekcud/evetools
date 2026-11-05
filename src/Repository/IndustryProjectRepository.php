<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Enum\GroupProjectStatus;
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
