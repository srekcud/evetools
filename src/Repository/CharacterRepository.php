<?php

declare(strict_types=1);

namespace App\Repository;

use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\AuthStatus;
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
    public function findWithValidTokens(): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.eveToken', 't')
            ->join('c.user', 'u')
            ->where('u.authStatus = :validStatus')
            ->setParameter('validStatus', AuthStatus::Valid)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Character[]
     */
    public function findActiveWithValidTokens(int $activeDays = 7): array
    {
        $threshold = new \DateTimeImmutable("-{$activeDays} days");

        return $this->createQueryBuilder('c')
            ->join('c.eveToken', 't')
            ->join('c.user', 'u')
            ->where('u.authStatus = :validStatus')
            ->andWhere('u.lastLoginAt >= :threshold')
            ->setParameter('validStatus', AuthStatus::Valid)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * The character whose token syncs a structure market: it belongs to a user who requested the structure
     * (preferred market structure, or no preference at all for the default structure) and carries the
     * structure market scope. The most recently logged-in user wins.
     */
    public function findStructureMarketRequester(int $structureId, bool $isDefaultStructure): ?Character
    {
        $requestedStructure = $isDefaultStructure
            ? '(u.preferredMarketStructureId = :structureId OR u.preferredMarketStructureId IS NULL)'
            : 'u.preferredMarketStructureId = :structureId';

        /** @var Character[] $candidates */
        $candidates = $this->createQueryBuilder('c')
            ->join('c.eveToken', 't')
            ->join('c.user', 'u')
            ->addSelect('CASE WHEN u.lastLoginAt IS NULL THEN 1 ELSE 0 END AS HIDDEN neverLoggedIn')
            ->where('u.authStatus = :validStatus')
            ->andWhere($requestedStructure)
            ->setParameter('validStatus', AuthStatus::Valid)
            ->setParameter('structureId', $structureId)
            ->orderBy('neverLoggedIn', 'ASC')
            ->addOrderBy('u.lastLoginAt', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $character) {
            if ($character->getEveToken()?->hasScope(EveConstants::STRUCTURE_MARKET_SCOPE) === true) {
                return $character;
            }
        }

        return null;
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
            ->setParameter('validStatus', AuthStatus::Valid)
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
