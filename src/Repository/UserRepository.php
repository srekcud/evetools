<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->remove($user);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByMainCharacterId(int $eveCharacterId): ?User
    {
        return $this->createQueryBuilder('u')
            ->join('u.mainCharacter', 'c')
            ->where('c.eveCharacterId = :eveCharacterId')
            ->setParameter('eveCharacterId', $eveCharacterId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return User[]
     */
    public function findWithInvalidAuth(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.authStatus = :status')
            ->setParameter('status', User::AUTH_STATUS_INVALID)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function findAllWithCharacters(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.characters', 'c')
            ->addSelect('c')
            ->leftJoin('c.eveToken', 't')
            ->addSelect('t')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function findActiveWithCharacters(int $activeDays = 7): array
    {
        $threshold = new \DateTimeImmutable("-{$activeDays} days");

        return $this->createQueryBuilder('u')
            ->leftJoin('u.characters', 'c')
            ->addSelect('c')
            ->leftJoin('c.eveToken', 't')
            ->addSelect('t')
            ->where('u.authStatus = :status')
            ->andWhere('u.lastLoginAt >= :threshold')
            ->setParameter('status', User::AUTH_STATUS_VALID)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }
}
