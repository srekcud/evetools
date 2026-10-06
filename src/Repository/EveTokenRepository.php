<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\EveToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveToken>
 */
class EveTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveToken::class);
    }

    public function save(EveToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EveToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->remove($token);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByCharacter(Character $character): ?EveToken
    {
        return $this->findOneBy(['character' => $character]);
    }
}
