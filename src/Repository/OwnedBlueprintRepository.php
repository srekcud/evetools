<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OwnedBlueprint>
 */
class OwnedBlueprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OwnedBlueprint::class);
    }

    /**
     * @param list<Character> $characters
     *
     * @return list<OwnedBlueprint>
     */
    public function findByCharacters(array $characters): array
    {
        if ($characters === []) {
            return [];
        }

        return array_values($this->createQueryBuilder('b')
            ->where('b.character IN (:characters)')
            ->setParameter('characters', $characters)
            ->orderBy('b.itemId', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<OwnedBlueprint> */
    public function findByCorporationId(int $corporationId): array
    {
        return array_values($this->createQueryBuilder('b')
            ->where('b.corporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->orderBy('b.itemId', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function deleteByCharacter(Character $character): int
    {
        return (int) $this->createQueryBuilder('b')
            ->delete()
            ->where('b.character = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->execute();
    }

    public function deleteByCorporationId(int $corporationId): int
    {
        return (int) $this->createQueryBuilder('b')
            ->delete()
            ->where('b.corporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->getQuery()
            ->execute();
    }
}
