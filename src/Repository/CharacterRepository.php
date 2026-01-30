<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Character>
 */
class CharacterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Character::class);
    }

    public function save(Character $character, bool $flush = false): void
    {
        $this->getEntityManager()->persist($character);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Character $character, bool $flush = false): void
    {
        $this->getEntityManager()->remove($character);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByEveCharacterId(int $eveCharacterId): ?Character
    {
        return $this->findOneBy(['eveCharacterId' => $eveCharacterId]);
    }

    /**
     * @return Character[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->leftJoin('c.eveToken', 't')
            ->addSelect('t')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Character[]
     */
    public function findByCorporationId(int $corporationId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.corporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->leftJoin('c.eveToken', 't')
            ->addSelect('t')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Character[]
     */
    public function findNeedingSync(\DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.eveToken', 't')
            ->addSelect('t')
            ->where('c.lastSyncAt IS NULL OR c.lastSyncAt < :threshold')
            ->andWhere('t.id IS NOT NULL')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Character[]
     */
    public function findWithValidTokens(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.eveToken', 't')
            ->join('c.user', 'u')
            ->where('u.authStatus = :validStatus')
            ->setParameter('validStatus', User::AUTH_STATUS_VALID)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a character that can access corporation assets for a given corporation.
     * The character must have a valid token with the esi-assets.read_corporation_assets.v1 scope.
     */
    public function findWithCorpAssetsAccess(int $corporationId): ?Character
    {
        return $this->findWithCorpScope($corporationId, 'esi-assets.read_corporation_assets.v1');
    }

    /**
     * Find a character that can access corporation divisions for a given corporation.
     */
    public function findWithCorpDivisionsAccess(int $corporationId): ?Character
    {
        return $this->findWithCorpScope($corporationId, 'esi-corporations.read_divisions.v1');
    }

    /**
     * Find a character in a corporation with a specific scope.
     */
    private function findWithCorpScope(int $corporationId, string $scope): ?Character
    {
        $characters = $this->createQueryBuilder('c')
            ->join('c.eveToken', 't')
            ->join('c.user', 'u')
            ->where('c.corporationId = :corporationId')
            ->andWhere('u.authStatus = :validStatus')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('validStatus', User::AUTH_STATUS_VALID)
            ->getQuery()
            ->getResult();

        foreach ($characters as $character) {
            $token = $character->getEveToken();
            if ($token !== null && $token->hasScope($scope)) {
                return $character;
            }
        }

        return null;
    }
}
