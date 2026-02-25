<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\SlotTrackerService;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlotTrackerService::class)]
#[AllowMockObjectsWithoutExpectations]
class SlotTrackerServiceTest extends TestCase
{
    private CharacterRepository&MockObject $characterRepository;
    private CachedIndustryJobRepository&MockObject $jobRepository;
    private CachedCharacterSkillRepository&MockObject $skillRepository;
    private IndustryCalculationService&MockObject $calculationService;
    private TypeNameResolver&MockObject $typeNameResolver;
    private IndustryStockpileTargetRepository&MockObject $stockpileTargetRepository;
    private CachedAssetRepository&MockObject $assetRepository;
    private SlotTrackerService $service;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->characterRepository = $this->createMock(CharacterRepository::class);
        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->skillRepository = $this->createMock(CachedCharacterSkillRepository::class);
        $this->calculationService = $this->createMock(IndustryCalculationService::class);
        $this->typeNameResolver = $this->createMock(TypeNameResolver::class);
        $this->stockpileTargetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $this->assetRepository = $this->createMock(CachedAssetRepository::class);

        $this->service = new SlotTrackerService(
            $this->characterRepository,
            $this->jobRepository,
            $this->skillRepository,
            $this->calculationService,
            $this->typeNameResolver,
            $this->stockpileTargetRepository,
            $this->assetRepository,
        );

        $this->user = $this->createMock(User::class);
    }

    public function testCalculateManufacturingMaxSlots(): void
    {
        $skills = $this->buildSkillMap([
            CachedCharacterSkill::SKILL_MASS_PRODUCTION => 5,
            CachedCharacterSkill::SKILL_ADVANCED_MASS_PRODUCTION => 5,
        ]);

        $result = SlotTrackerService::calculateMaxSlots($skills);

        $this->assertSame(11, $result['manufacturing']);
    }

    public function testCalculateReactionMaxSlots(): void
    {
        $skills = $this->buildSkillMap([
            CachedCharacterSkill::SKILL_MASS_REACTIONS => 5,
            CachedCharacterSkill::SKILL_ADVANCED_REACTIONS => 5,
        ]);

        $result = SlotTrackerService::calculateMaxSlots($skills);

        $this->assertSame(11, $result['reaction']);
    }

    public function testCalculateScienceMaxSlots(): void
    {
        $skills = $this->buildSkillMap([
            CachedCharacterSkill::SKILL_LABORATORY_OPERATION => 5,
            CachedCharacterSkill::SKILL_ADVANCED_LABORATORY_OPERATION => 5,
        ]);

        $result = SlotTrackerService::calculateMaxSlots($skills);

        $this->assertSame(11, $result['science']);
    }

    public function testNoSkillsDefaultsToBaseSlot(): void
    {
        $result = SlotTrackerService::calculateMaxSlots([]);

        $this->assertSame(1, $result['manufacturing']);
        $this->assertSame(1, $result['reaction']);
        $this->assertSame(1, $result['science']);
    }

    public function testPartialSkillLevels(): void
    {
        $skills = $this->buildSkillMap([
            CachedCharacterSkill::SKILL_MASS_PRODUCTION => 3,
            // No Advanced Mass Production
            CachedCharacterSkill::SKILL_MASS_REACTIONS => 2,
            CachedCharacterSkill::SKILL_ADVANCED_REACTIONS => 1,
        ]);

        $result = SlotTrackerService::calculateMaxSlots($skills);

        $this->assertSame(4, $result['manufacturing']); // 1 + 3 + 0
        $this->assertSame(4, $result['reaction']); // 1 + 2 + 1
        $this->assertSame(1, $result['science']); // 1 + 0 + 0
    }

    /**
     * @param array{activityId: int, expectedSlot: string} $case
     */
    #[DataProvider('activitySlotProvider')]
    public function testActivityIdToSlotType(int $activityId, string $expectedSlotType): void
    {
        $this->assertSame($expectedSlotType, SlotTrackerService::getSlotCategory($activityId));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function activitySlotProvider(): iterable
    {
        yield 'manufacturing' => [1, 'manufacturing'];
        yield 'reaction (9)' => [9, 'reaction'];
        yield 'reaction (11)' => [11, 'reaction'];
        yield 'research_te' => [3, 'science'];
        yield 'research_me' => [4, 'science'];
        yield 'copying' => [5, 'science'];
        yield 'reverse_engineering' => [7, 'science'];
        yield 'invention' => [8, 'science'];
    }

    /**
     * @param array{activityId: int, expectedType: string} $case
     */
    #[DataProvider('activityTypeProvider')]
    public function testActivityIdToActivityType(int $activityId, string $expectedType): void
    {
        $this->assertSame($expectedType, SlotTrackerService::getActivityType($activityId));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function activityTypeProvider(): iterable
    {
        yield 'manufacturing' => [1, 'manufacturing'];
        yield 'research_te' => [3, 'research_te'];
        yield 'research_me' => [4, 'research_me'];
        yield 'copying' => [5, 'copying'];
        yield 'reverse_engineering' => [7, 'reverse_engineering'];
        yield 'invention' => [8, 'invention'];
        yield 'reaction (9)' => [9, 'reaction'];
        yield 'reaction (11)' => [11, 'reaction'];
    }

    public function testUnknownActivityIdReturnsNull(): void
    {
        $this->assertNull(SlotTrackerService::getSlotCategory(999));
        $this->assertNull(SlotTrackerService::getActivityType(999));
    }

    public function testProgressCalculationMidway(): void
    {
        $start = new \DateTimeImmutable('2026-02-23 00:00:00');
        $end = new \DateTimeImmutable('2026-02-23 10:00:00');
        $now = new \DateTimeImmutable('2026-02-23 05:00:00');

        $progress = SlotTrackerService::calculateProgress($start, $end, $now);

        $this->assertSame(50.0, $progress);
    }

    public function testProgressCalculationCompleted(): void
    {
        $start = new \DateTimeImmutable('2026-02-23 00:00:00');
        $end = new \DateTimeImmutable('2026-02-23 10:00:00');
        $now = new \DateTimeImmutable('2026-02-23 12:00:00'); // past end

        $progress = SlotTrackerService::calculateProgress($start, $end, $now);

        $this->assertSame(100.0, $progress);
    }

    public function testProgressCalculationNotStarted(): void
    {
        $start = new \DateTimeImmutable('2026-02-23 10:00:00');
        $end = new \DateTimeImmutable('2026-02-23 20:00:00');
        $now = new \DateTimeImmutable('2026-02-23 08:00:00'); // before start

        $progress = SlotTrackerService::calculateProgress($start, $end, $now);

        $this->assertSame(0.0, $progress);
    }

    public function testProgressCalculationZeroDuration(): void
    {
        $start = new \DateTimeImmutable('2026-02-23 10:00:00');
        $end = new \DateTimeImmutable('2026-02-23 10:00:00'); // same as start
        $now = new \DateTimeImmutable('2026-02-23 10:00:00');

        $progress = SlotTrackerService::calculateProgress($start, $end, $now);

        $this->assertSame(100.0, $progress);
    }

    public function testTimeLeftCalculation(): void
    {
        $end = new \DateTimeImmutable('2026-02-23 10:00:00');
        $now = new \DateTimeImmutable('2026-02-23 07:00:00');

        $timeLeft = SlotTrackerService::calculateTimeLeft($end, $now);

        $this->assertSame(3 * 3600, $timeLeft); // 3 hours
    }

    public function testTimeLeftNeverNegative(): void
    {
        $end = new \DateTimeImmutable('2026-02-23 10:00:00');
        $now = new \DateTimeImmutable('2026-02-23 12:00:00'); // past end

        $timeLeft = SlotTrackerService::calculateTimeLeft($end, $now);

        $this->assertSame(0, $timeLeft);
    }

    public function testGlobalKpiAggregation(): void
    {
        // Two characters, each with manufacturing jobs
        $char1 = $this->createCharacterMock(1001, 'Char One', true);
        $char2 = $this->createCharacterMock(1002, 'Char Two', false);

        $this->characterRepository->method('findByUser')->willReturn([$char1, $char2]);

        // Char1: 3 manufacturing jobs, skills for 5 max manufacturing slots
        $job1 = $this->createJobMock(1, 100, 1, 10, '2026-02-23 00:00:00', '+2 days');
        $job2 = $this->createJobMock(2, 101, 1, 5, '2026-02-23 01:00:00', '+1 day');
        $job3 = $this->createJobMock(3, 102, 1, 1, '2026-02-23 02:00:00', '+3 hours');

        // Char2: 1 manufacturing job, skills for 3 max manufacturing slots
        $job4 = $this->createJobMock(4, 103, 1, 2, '2026-02-23 00:00:00', '+3 days');

        $this->jobRepository->method('findActiveJobsByCharacter')
            ->willReturnCallback(fn (Character $c) => match ($c->getEveCharacterId()) {
                1001 => [$job1, $job2, $job3],
                1002 => [$job4],
                default => [],
            });

        // Char1: Mass Production 4
        $this->skillRepository->method('findSlotSkillsForCharacter')
            ->willReturnCallback(fn (Character $c) => match ($c->getEveCharacterId()) {
                1001 => $this->buildSkillMap([CachedCharacterSkill::SKILL_MASS_PRODUCTION => 4]),
                1002 => $this->buildSkillMap([CachedCharacterSkill::SKILL_MASS_PRODUCTION => 2]),
                default => [],
            });

        $this->typeNameResolver->method('resolveMany')->willReturn([
            100 => 'Widget A', 101 => 'Widget B', 102 => 'Widget C', 103 => 'Widget D',
        ]);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Global manufacturing: used = 3 + 1 = 4, max = (1+4) + (1+2) = 8
        $this->assertSame(4, $result['globalKpis']['manufacturing']['used']);
        $this->assertSame(8, $result['globalKpis']['manufacturing']['max']);
        $this->assertSame(50.0, $result['globalKpis']['manufacturing']['percent']);

        // Reaction and science: no jobs, base slot per character
        $this->assertSame(0, $result['globalKpis']['reaction']['used']);
        $this->assertSame(2, $result['globalKpis']['reaction']['max']); // 1 + 1 (base per char)
        $this->assertSame(0, $result['globalKpis']['science']['used']);
        $this->assertSame(2, $result['globalKpis']['science']['max']);
    }

    public function testCharacterSortingMainFirst(): void
    {
        $charMain = $this->createCharacterMock(1001, 'Zephyr', true);
        $charAlt1 = $this->createCharacterMock(1002, 'Alpha', false);
        $charAlt2 = $this->createCharacterMock(1003, 'Bravo', false);

        $this->characterRepository->method('findByUser')->willReturn([$charAlt1, $charMain, $charAlt2]);
        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn([]);
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn([]);
        $this->typeNameResolver->method('resolveMany')->willReturn([]);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);

        $result = $this->service->getSlotStatus($this->user);

        // Main character first, then alts alphabetically
        $this->assertSame('Zephyr', $result['characters'][0]['characterName']);
        $this->assertTrue($result['characters'][0]['isMain']);
        $this->assertSame('Alpha', $result['characters'][1]['characterName']);
        $this->assertSame('Bravo', $result['characters'][2]['characterName']);
    }

    public function testTimelineSortedByEndDate(): void
    {
        $char = $this->createCharacterMock(1001, 'Pilot', true);
        $this->characterRepository->method('findByUser')->willReturn([$char]);

        $jobLate = $this->createJobMock(1, 100, 1, 10, '2026-02-23 00:00:00', '2026-12-23 20:00:00');
        $jobEarly = $this->createJobMock(2, 101, 1, 5, '2026-02-23 01:00:00', '2026-12-23 05:00:00');
        $jobMid = $this->createJobMock(3, 102, 1, 1, '2026-02-23 02:00:00', '2026-12-23 10:00:00');

        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn([$jobLate, $jobEarly, $jobMid]);
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn([]);
        $this->typeNameResolver->method('resolveMany')->willReturn([100 => 'A', 101 => 'B', 102 => 'C']);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Timeline sorted by endDate ascending
        $this->assertSame(2, $result['timeline'][0]['jobId']); // earliest end
        $this->assertSame(3, $result['timeline'][1]['jobId']); // mid end
        $this->assertSame(1, $result['timeline'][2]['jobId']); // latest end
    }

    public function testEmptyCharacterList(): void
    {
        $this->characterRepository->method('findByUser')->willReturn([]);
        $this->typeNameResolver->method('resolveMany')->willReturn([]);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);

        $result = $this->service->getSlotStatus($this->user);

        $this->assertEmpty($result['characters']);
        $this->assertEmpty($result['timeline']);
        $this->assertSame(0, $result['globalKpis']['manufacturing']['used']);
        $this->assertSame(0, $result['globalKpis']['manufacturing']['max']);
    }

    public function testReactionSlotsUseMassReactionsSkill(): void
    {
        // Mass Reactions (45749) at level 4, Advanced Mass Reactions at level 5
        // Expected: 1 + 4 + 5 = 10 (NOT 11)
        $skills = $this->buildSkillMap([
            CachedCharacterSkill::SKILL_MASS_REACTIONS => 4,
            CachedCharacterSkill::SKILL_ADVANCED_REACTIONS => 5,
        ]);

        $result = SlotTrackerService::calculateMaxSlots($skills);

        $this->assertSame(10, $result['reaction']);
    }

    public function testReactionSlotsIgnoresReactionsTimeSkill(): void
    {
        // SKILL_REACTIONS (45746) is the time reduction skill, not the slot skill.
        // It should NOT contribute to slot count.
        $skills = $this->buildSkillMap([
            CachedCharacterSkill::SKILL_REACTIONS => 5, // Time skill, not slot skill
        ]);

        $result = SlotTrackerService::calculateMaxSlots($skills);

        // Only base slot (1), SKILL_REACTIONS should not add slots
        $this->assertSame(1, $result['reaction']);
    }

    public function testReadyJobsCountForSlotsButNotInJobList(): void
    {
        $char = $this->createCharacterMock(1001, 'Epsilon', true);
        $this->characterRepository->method('findByUser')->willReturn([$char]);

        // 1 active science job + 1 ready (completed) science job
        $activeJob = $this->createJobMock(1, 100, 4, 1, '2026-02-23 00:00:00', '2026-12-24 10:00:00', null, 'active');
        $readyJob = $this->createJobMock(2, 101, 3, 1, '2026-02-20 00:00:00', '2026-02-22 00:00:00', null, 'ready');

        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn([$activeJob, $readyJob]);
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn(
            $this->buildSkillMap([CachedCharacterSkill::SKILL_LABORATORY_OPERATION => 5])
        );
        $this->typeNameResolver->method('resolveMany')->willReturn([100 => 'Blueprint A', 101 => 'Blueprint B']);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Both jobs count toward slot usage
        $this->assertSame(2, $result['globalKpis']['science']['used']);
        $this->assertSame(2, $result['characters'][0]['slots']['science']['used']);

        // Only the active job appears in the job list
        $this->assertCount(1, $result['characters'][0]['jobs']);
        $this->assertSame(1, $result['characters'][0]['jobs'][0]['jobId']);

        // Only the active job appears in the timeline
        $this->assertCount(1, $result['timeline']);
        $this->assertSame(1, $result['timeline'][0]['jobId']);
    }

    public function testAllReadyJobsStillCountSlotsButNoJobEntries(): void
    {
        $char = $this->createCharacterMock(1001, 'Pilot', true);
        $this->characterRepository->method('findByUser')->willReturn([$char]);

        // 2 ready manufacturing jobs, 0 active
        $readyJob1 = $this->createJobMock(1, 100, 1, 10, '2026-02-20 00:00:00', '2026-02-22 00:00:00', null, 'ready');
        $readyJob2 = $this->createJobMock(2, 101, 1, 5, '2026-02-20 00:00:00', '2026-02-22 00:00:00', null, 'ready');

        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn([$readyJob1, $readyJob2]);
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn(
            $this->buildSkillMap([CachedCharacterSkill::SKILL_MASS_PRODUCTION => 5])
        );
        $this->typeNameResolver->method('resolveMany')->willReturn([100 => 'A', 101 => 'B']);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Slots are used (ready jobs occupy slots)
        $this->assertSame(2, $result['globalKpis']['manufacturing']['used']);

        // But no job entries or timeline entries (all are ready, not active)
        $this->assertEmpty($result['characters'][0]['jobs']);
        $this->assertEmpty($result['timeline']);
    }

    public function testActiveJobsWithPastEndDateDoNotCountForSlotsOrJobList(): void
    {
        $char = $this->createCharacterMock(1001, 'Researcher', true);
        $this->characterRepository->method('findByUser')->willReturn([$char]);

        // 1 active manufacturing job still running (end far in the future)
        $runningJob = $this->createJobMock(1, 100, 1, 10, '2026-02-23 00:00:00', '2026-12-25 10:00:00', null, 'active');
        // 2 active science jobs whose end_date is in the past (completed but ESI not yet updated)
        $completedResearchTe = $this->createJobMock(2, 101, 3, 1, '2026-02-20 00:00:00', '2026-02-22 00:00:00', null, 'active');
        $completedResearchMe = $this->createJobMock(3, 102, 4, 1, '2026-02-19 00:00:00', '2026-02-21 00:00:00', null, 'active');

        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn([$runningJob, $completedResearchTe, $completedResearchMe]);
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn(
            $this->buildSkillMap([
                CachedCharacterSkill::SKILL_MASS_PRODUCTION => 5,
                CachedCharacterSkill::SKILL_LABORATORY_OPERATION => 5,
            ])
        );
        $this->typeNameResolver->method('resolveMany')->willReturn([100 => 'Ferox', 101 => 'BP TE', 102 => 'BP ME']);
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Only the running manufacturing job counts toward slot usage
        // Active jobs with past endDate are effectively completed and do not occupy slots
        $this->assertSame(1, $result['globalKpis']['manufacturing']['used']);
        $this->assertSame(0, $result['globalKpis']['science']['used']);

        // Only the running manufacturing job appears in the job list
        $this->assertCount(1, $result['characters'][0]['jobs']);
        $this->assertSame(1, $result['characters'][0]['jobs'][0]['jobId']);

        // Only the running job appears in the timeline
        $this->assertCount(1, $result['timeline']);
        $this->assertSame(1, $result['timeline'][0]['jobId']);
    }

    public function testSkillsMayBeStaleWhenUsedExceedsMax(): void
    {
        // 1 character with 5 active reaction jobs but no skills synced (max = 1 base)
        $char = $this->createCharacterMock(1001, 'Reactor', true);
        $this->characterRepository->method('findByUser')->willReturn([$char]);

        // 5 active reaction jobs (activity 9 = reaction)
        $jobs = [];
        for ($i = 1; $i <= 5; $i++) {
            $jobs[] = $this->createJobMock($i, 100 + $i, 9, 1, '2026-02-23 00:00:00', '2026-12-25 00:00:00');
        }

        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn($jobs);
        // No skills synced: empty array -> max = 1 (base) per category
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn([]);
        $this->typeNameResolver->method('resolveMany')->willReturn(
            array_combine(range(101, 105), array_map(fn ($i) => "Reaction $i", range(1, 5)))
        );
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Skills are stale: 5 used > 1 max (base only)
        $this->assertTrue($result['skillsMayBeStale']);

        // Max should be adjusted to at least used so percent <= 100%
        $this->assertSame(5, $result['globalKpis']['reaction']['used']);
        $this->assertSame(5, $result['globalKpis']['reaction']['max']);
        $this->assertSame(100.0, $result['globalKpis']['reaction']['percent']);
    }

    public function testSkillsNotStaleWhenUsedWithinMax(): void
    {
        // 1 character with 3 manufacturing jobs and skills synced for 6 max
        $char = $this->createCharacterMock(1001, 'Industrialist', true);
        $this->characterRepository->method('findByUser')->willReturn([$char]);

        $jobs = [];
        for ($i = 1; $i <= 3; $i++) {
            $jobs[] = $this->createJobMock($i, 100 + $i, 1, 1, '2026-02-23 00:00:00', '2026-12-25 00:00:00');
        }

        $this->jobRepository->method('findActiveJobsByCharacter')->willReturn($jobs);
        // Mass Production 5 -> max = 1 + 5 = 6
        $this->skillRepository->method('findSlotSkillsForCharacter')->willReturn(
            $this->buildSkillMap([CachedCharacterSkill::SKILL_MASS_PRODUCTION => 5])
        );
        $this->typeNameResolver->method('resolveMany')->willReturn(
            array_combine(range(101, 103), ['Ship A', 'Ship B', 'Ship C'])
        );
        $this->stockpileTargetRepository->method('findByUser')->willReturn([]);
        $this->calculationService->method('resolveFacilityName')->willReturn(null);

        $result = $this->service->getSlotStatus($this->user);

        // Skills are NOT stale: 3 used <= 6 max
        $this->assertFalse($result['skillsMayBeStale']);
        $this->assertSame(3, $result['globalKpis']['manufacturing']['used']);
        $this->assertSame(6, $result['globalKpis']['manufacturing']['max']);
        $this->assertSame(50.0, $result['globalKpis']['manufacturing']['percent']);
    }

    /**
     * Build a mock skill map indexed by skill ID.
     *
     * @param array<int, int> $skillLevels skillId => level
     * @return array<int, CachedCharacterSkill&MockObject>
     */
    private function buildSkillMap(array $skillLevels): array
    {
        $map = [];
        foreach ($skillLevels as $skillId => $level) {
            $skill = $this->createMock(CachedCharacterSkill::class);
            $skill->method('getSkillId')->willReturn($skillId);
            $skill->method('getLevel')->willReturn($level);
            $map[$skillId] = $skill;
        }

        return $map;
    }

    private function createCharacterMock(int $eveCharacterId, string $name, bool $isMain): Character&MockObject
    {
        $character = $this->createMock(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getName')->willReturn($name);
        $character->method('isMain')->willReturn($isMain);

        return $character;
    }

    private function createJobMock(
        int $jobId,
        int $productTypeId,
        int $activityId,
        int $runs,
        string $startDate,
        string $endDate,
        ?int $stationId = null,
        string $status = 'active',
    ): CachedIndustryJob&MockObject {
        $job = $this->createMock(CachedIndustryJob::class);
        $job->method('getJobId')->willReturn($jobId);
        $job->method('getProductTypeId')->willReturn($productTypeId);
        $job->method('getActivityId')->willReturn($activityId);
        $job->method('getRuns')->willReturn($runs);
        $job->method('getStartDate')->willReturn(new \DateTimeImmutable($startDate));
        $job->method('getEndDate')->willReturn(new \DateTimeImmutable($endDate));
        $job->method('getStationId')->willReturn($stationId);
        $job->method('getStatus')->willReturn($status);

        return $job;
    }
}
