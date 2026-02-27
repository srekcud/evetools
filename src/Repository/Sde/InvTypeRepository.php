<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\InvType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvType>
 */
class InvTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvType::class);
    }

    public function findByTypeId(int $typeId): ?InvType
    {
        return $this->find($typeId);
    }

    /**
     * @param int[] $typeIds
     * @return array<int, InvType>
     */
    public function findByTypeIds(array $typeIds): array
    {
        $types = $this->createQueryBuilder('t')
            ->leftJoin('t.group', 'g')
            ->addSelect('g')
            ->leftJoin('g.category', 'c')
            ->addSelect('c')
            ->where('t.typeId IN (:typeIds)')
            ->setParameter('typeIds', $typeIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($types as $type) {
            $indexed[$type->getTypeId()] = $type;
        }

        return $indexed;
    }

    /**
     * @return InvType[]
     */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.published = :published')
            ->setParameter('published', true)
            ->getQuery()
            ->getResult();
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->getQuery()
            ->execute();
    }

    /**
     * Find a single published type by exact name (case-insensitive).
     * Returns null if no match is found.
     */
    public function findOneByName(string $name): ?InvType
    {
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.typeName) = LOWER(:name)')
            ->andWhere('t.published = :published')
            ->setParameter('name', $name)
            ->setParameter('published', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return InvType[]
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        // Prioritize: exact match > starts with > contains
        // Also exclude SKINs from results to show actual items first
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.typeName) LIKE LOWER(:query)')
            ->andWhere('t.published = :published')
            ->setParameter('query', '%' . $query . '%')
            ->setParameter('published', true)
            ->orderBy('CASE
                WHEN LOWER(t.typeName) = LOWER(:exactQuery) THEN 0
                WHEN LOWER(t.typeName) LIKE LOWER(:startsWithQuery) THEN 1
                WHEN LOWER(t.typeName) LIKE :skinPattern THEN 3
                ELSE 2
            END', 'ASC')
            ->addOrderBy('t.typeName', 'ASC')
            ->setParameter('exactQuery', $query)
            ->setParameter('startsWithQuery', $query . '%')
            ->setParameter('skinPattern', '% SKIN')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
