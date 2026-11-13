<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterWalletReading>
 */
class CharacterWalletReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterWalletReading::class);
    }

    public function findOneByCharacter(Character $character): ?CharacterWalletReading
    {
        return null;
    }

    /**
     * @param list<Character> $characters
     * @return list<CharacterWalletReading>
     */
    public function findByCharacters(array $characters): array
    {
        return [];
    }
}
