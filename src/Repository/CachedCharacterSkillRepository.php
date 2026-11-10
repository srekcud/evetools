<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedCharacterSkill>
 */
class CachedCharacterSkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedCharacterSkill::class);
    }

    /**
     * Get slot-related skills for a character, indexed by skill ID.
     *
     * @return array<int, CachedCharacterSkill>
     */
    public function findSlotSkillsForCharacter(Character $character): array
    {
        $skills = $this->createQueryBuilder('s')
            ->where('s.character = :character')
            ->andWhere('s.skillId IN (:skillIds)')
            ->setParameter('character', $character)
            ->setParameter('skillIds', CachedCharacterSkill::SLOT_SKILL_IDS)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($skills as $skill) {
            $indexed[$skill->getSkillId()] = $skill;
        }

        return $indexed;
    }

    public function hasCachedSkills(Character $character): bool
    {
        return $this->createQueryBuilder('s')
            ->select('1')
            ->where('s.character = :character')
            ->setParameter('character', $character)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * Get ALL cached skills for a character, indexed by skill ID.
     *
     * @return array<int, CachedCharacterSkill>
     */
    public function findAllSkillsForCharacter(Character $character): array
    {
        $skills = $this->createQueryBuilder('s')
            ->where('s.character = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($skills as $skill) {
            $indexed[$skill->getSkillId()] = $skill;
        }

        return $indexed;
    }

    /**
     * @return array<int, int>
     */
    public function findLevelsByEveCharacterId(int $eveCharacterId): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.skillId AS skillId', 's.level AS level')
            ->join('s.character', 'c')
            ->where('c.eveCharacterId = :eveCharacterId')
            ->setParameter('eveCharacterId', $eveCharacterId)
            ->getQuery()
            ->getArrayResult();

        $levels = [];
        foreach ($rows as $row) {
            $levels[$row['skillId']] = $row['level'];
        }

        return $levels;
    }
}
