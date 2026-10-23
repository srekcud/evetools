<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Repository\CachedCharacterSkillRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

final class CachedCharacterSkillRepositoryTest extends IntegrationTestCase
{
    private CachedCharacterSkillRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedCharacterSkillRepository::class);
    }

    #[Group('issue-218')]
    public function testCharacterWithOnlyANonSlotSkillCachedHasCachedSkills(): void
    {
        $character = $this->createCharacter(name: 'Synced Pilot');
        $this->cacheSkill($character, CachedCharacterSkill::SKILL_INDUSTRY, 5);
        $this->flushAndClear();

        self::assertTrue($this->repository->hasCachedSkills($this->em->find(Character::class, $character->getId())));
    }

    #[Group('issue-218')]
    public function testCharacterWithoutCachedSkillHasNoCachedSkillsWhileAnotherCharacterHasSome(): void
    {
        $neverSynced = $this->createCharacter(name: 'Never Synced Pilot');
        $this->cacheSkill($this->createCharacter(name: 'Synced Pilot'), CachedCharacterSkill::SKILL_MASS_PRODUCTION, 4);
        $this->flushAndClear();

        self::assertFalse($this->repository->hasCachedSkills($this->em->find(Character::class, $neverSynced->getId())));
    }

    private function cacheSkill(Character $character, int $skillId, int $level): void
    {
        $this->em->persist((new CachedCharacterSkill())->setCharacter($character)->setSkillId($skillId)->setLevel($level));
    }
}
