<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryProject;
use App\Enum\GroupProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupIndustryProject>
 */
class GroupIndustryProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupIndustryProject::class);
    }

    /**
     * Find projects owned by users in a given corporation, filtered by status.
     *
     * @param GroupProjectStatus[] $statuses
     * @return GroupIndustryProject[]
     */
    public function findByOwnerCorporation(int $corporationId, array $statuses): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.owner', 'owner')
            ->join('owner.mainCharacter', 'mc')
            ->where('mc.corporationId = :corpId')
            ->setParameter('corpId', $corporationId);

        if (!empty($statuses)) {
            $qb->andWhere('p.status IN (:statuses)')
                ->setParameter('statuses', array_map(fn (GroupProjectStatus $s) => $s->value, $statuses));
        }

        $qb->orderBy('p.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function findByShortLinkCode(string $code): ?GroupIndustryProject
    {
        return $this->findOneBy(['shortLinkCode' => $code]);
    }
}
