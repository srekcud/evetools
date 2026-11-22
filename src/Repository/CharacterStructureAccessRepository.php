<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterStructureAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterStructureAccess>
 */
class CharacterStructureAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterStructureAccess::class);
    }

    public function findOneByCharacterAndStructure(Character $character, int $structureId): ?CharacterStructureAccess
    {
        return $this->findOneBy(['character' => $character, 'structureId' => $structureId]);
    }
}
