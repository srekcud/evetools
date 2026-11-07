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
        return [];
    }

    /** @return list<OwnedBlueprint> */
    public function findByCorporationId(int $corporationId): array
    {
        return [];
    }

    public function deleteByCharacter(Character $character): int
    {
        return 0;
    }

    public function deleteByCorporationId(int $corporationId): int
    {
        return 0;
    }
}
