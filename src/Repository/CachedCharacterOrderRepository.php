<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedCharacterOrder>
 */
class CachedCharacterOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedCharacterOrder::class);
    }

    /**
     * @return list<CachedCharacterOrder>
     */
    public function findByCharacter(Character $character): array
    {
        return $this->findBy(['character' => $character]);
    }

    /**
     * @param list<Character> $characters
     * @param list<int>       $typeIds
     *
     * @return list<CachedCharacterOrder>
     */
    public function findByCharactersAndTypes(array $characters, array $typeIds): array
    {
        if ($characters === [] || $typeIds === []) {
            return [];
        }

        return array_values($this->createQueryBuilder('o')
            ->where('o.character IN (:characters)')
            ->andWhere('o.typeId IN (:typeIds)')
            ->setParameter('characters', $characters)
            ->setParameter('typeIds', $typeIds)
            ->orderBy('o.orderId', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
