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
     * Get all industry skills for a character, indexed by skill ID.
     *
     * @return array<int, CachedCharacterSkill>
     */
    public function findIndustrySkillsForCharacter(Character $character): array
    {
        $skills = $this->createQueryBuilder('s')
            ->where('s.character = :character')
            ->andWhere('s.skillId IN (:skillIds)')
            ->setParameter('character', $character)
            ->setParameter('skillIds', CachedCharacterSkill::INDUSTRY_SKILL_IDS)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($skills as $skill) {
            $indexed[$skill->getSkillId()] = $skill;
        }

        return $indexed;
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
}
