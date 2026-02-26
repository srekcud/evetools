<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorpAssetVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorpAssetVisibility>
 */
class CorpAssetVisibilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorpAssetVisibility::class);
    }

    public function findByCorporationId(int $corporationId): ?CorpAssetVisibility
    {
        return $this->createQueryBuilder('v')
            ->where('v.corporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
