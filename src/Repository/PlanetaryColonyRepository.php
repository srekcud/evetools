<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanetaryColony>
 */
class PlanetaryColonyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetaryColony::class);
    }

    /**
     * @return PlanetaryColony[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.character', 'ch')
            ->where('ch.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ch.name', 'ASC')
            ->addOrderBy('c.planetType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PlanetaryColony[]
     */
    public function findByCharacter(Character $character): array
    {
        return $this->findBy(['character' => $character], ['planetType' => 'ASC']);
    }

    public function findByCharacterAndPlanet(Character $character, int $planetId): ?PlanetaryColony
    {
        return $this->findOneBy(['character' => $character, 'planetId' => $planetId]);
    }
}
