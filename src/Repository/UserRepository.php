<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\User;
use App\Enum\AuthStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    private const MAXIMUM_EVE_CHARACTER_ID_DIGITS = 18;

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

    /**
     * Get all distinct market structure IDs across users: preferred structures first, then every listed structure.
     *
     * @return list<int>
     */
    public function findDistinctMarketStructureIds(): array
    {
        $preferredRows = $this->createQueryBuilder('u')
            ->select('DISTINCT u.preferredMarketStructureId')
            ->where('u.preferredMarketStructureId IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        $listedStructureIds = [];
        foreach ($this->createQueryBuilder('u')->select('u.marketStructures')->getQuery()->getArrayResult() as $row) {
            foreach ($row['marketStructures'] as $structure) {
                $listedStructureIds[] = (int) $structure['id'];
            }
        }

        return array_values(array_unique([...array_map(intval(...), $preferredRows), ...$listedStructureIds]));
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
            ->where(sprintf(
                'EXISTS (SELECT validCharacter.id FROM %s validCharacter WHERE validCharacter.user = u AND validCharacter.authStatus = :status)',
                Character::class,
            ))
            ->andWhere('u.lastLoginAt >= :threshold')
            ->setParameter('status', AuthStatus::Valid)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function findPlayersOfCorporation(int $corporationId): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin('u.mainCharacter', 'mainCharacter')
            ->where('mainCharacter.corporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->orderBy('mainCharacter.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $eveCharacterIds
     *
     * @return list<User>
     */
    public function findAdminsByMainCharacterEveIds(array $eveCharacterIds): array
    {
        if ($eveCharacterIds === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->innerJoin('u.mainCharacter', 'mainCharacter')
            ->where('mainCharacter.eveCharacterId IN (:eveCharacterIds)')
            ->setParameter('eveCharacterIds', $eveCharacterIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function searchByCharacter(string $term, int $limit): array
    {
        $queryBuilder = $this->createQueryBuilder('u')
            ->innerJoin('u.characters', 'c')
            ->leftJoin('u.mainCharacter', 'm')
            ->setParameter('nameFragment', '%' . addcslashes(mb_strtolower($term), '%_\\') . '%')
            ->groupBy('u.id', 'm.name')
            ->orderBy('m.name', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->setMaxResults($limit);

        if (ctype_digit($term) && strlen($term) <= self::MAXIMUM_EVE_CHARACTER_ID_DIGITS) {
            $queryBuilder->where('LOWER(c.name) LIKE :nameFragment OR c.eveCharacterId = :eveCharacterId')
                ->setParameter('eveCharacterId', (int) $term);
        } else {
            $queryBuilder->where('LOWER(c.name) LIKE :nameFragment');
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
