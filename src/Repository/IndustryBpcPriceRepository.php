<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryBpcPrice;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryBpcPrice>
 */
class IndustryBpcPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryBpcPrice::class);
    }

    /**
     * Returns BPC prices for a user, indexed by blueprintTypeId.
     *
     * @return array<int, float> blueprintTypeId => pricePerRun
     */
    public function findByUserIndexed(User $user): array
    {
        /** @var list<array{blueprintTypeId: int, pricePerRun: float}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.blueprintTypeId', 'b.pricePerRun')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['blueprintTypeId']] = (float) $row['pricePerRun'];
        }

        return $result;
    }

    public function findByUserAndBlueprint(User $user, int $blueprintTypeId): ?IndustryBpcPrice
    {
        return $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->andWhere('b.blueprintTypeId = :blueprintTypeId')
            ->setParameter('user', $user)
            ->setParameter('blueprintTypeId', $blueprintTypeId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return IndustryBpcPrice[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
