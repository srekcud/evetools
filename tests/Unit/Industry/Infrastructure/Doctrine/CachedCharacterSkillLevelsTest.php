<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Doctrine;

use App\Entity\CachedCharacterSkill;
use App\Industry\Infrastructure\Doctrine\CachedCharacterSkillLevels;
use App\Repository\CachedCharacterSkillRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachedCharacterSkillLevels::class)]
#[Group('issue-475')]
final class CachedCharacterSkillLevelsTest extends TestCase
{
    private const int FIRST_RESEARCHER = 2_114_750_001;
    private const int SECOND_RESEARCHER = 2_114_750_002;

    public function testSkillLevelsOfACharacterAreReadOnceFromTheRepository(): void
    {
        $repository = $this->createMock(CachedCharacterSkillRepository::class);
        $repository->expects(self::once())->method('findLevelsByEveCharacterId')->with(self::FIRST_RESEARCHER)->willReturn([
            CachedCharacterSkill::SKILL_METALLURGY => 4,
            CachedCharacterSkill::SKILL_RESEARCH => 2,
        ]);
        $skillLevels = new CachedCharacterSkillLevels($repository);

        self::assertSame(4, $skillLevels->levelOf(self::FIRST_RESEARCHER, CachedCharacterSkill::SKILL_METALLURGY));
        self::assertSame(2, $skillLevels->levelOf(self::FIRST_RESEARCHER, CachedCharacterSkill::SKILL_RESEARCH));
        self::assertNull($skillLevels->levelOf(self::FIRST_RESEARCHER, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY));
    }

    public function testEachCharacterGetsItsOwnReadAndResetForcesANewRead(): void
    {
        $repository = $this->createMock(CachedCharacterSkillRepository::class);
        $repository->expects(self::exactly(3))->method('findLevelsByEveCharacterId')->willReturnCallback(
            static fn (int $eveCharacterId): array => [CachedCharacterSkill::SKILL_RESEARCH => self::FIRST_RESEARCHER === $eveCharacterId ? 3 : 5],
        );
        $skillLevels = new CachedCharacterSkillLevels($repository);

        self::assertSame(3, $skillLevels->levelOf(self::FIRST_RESEARCHER, CachedCharacterSkill::SKILL_RESEARCH));
        self::assertSame(5, $skillLevels->levelOf(self::SECOND_RESEARCHER, CachedCharacterSkill::SKILL_RESEARCH));

        $skillLevels->reset();

        self::assertSame(3, $skillLevels->levelOf(self::FIRST_RESEARCHER, CachedCharacterSkill::SKILL_RESEARCH));
    }
}
