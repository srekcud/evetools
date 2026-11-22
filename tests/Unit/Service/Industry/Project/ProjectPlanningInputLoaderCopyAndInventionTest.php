<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Industry\Application\PlannedJobTimeModifier;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Service\Industry\Project\PlanningCharacter;
use App\Service\Industry\Project\PlanningLeftOutLine;
use App\Service\Industry\Project\PlanningLine;
use App\Service\Industry\Project\PlanningUnscheduled;
use App\Service\Industry\Project\ProjectPlanningBuilder;
use App\Service\Industry\Project\ProjectPlanningInput;
use App\Service\Industry\Project\ProjectPlanningInputLoader;
use App\Tests\Unit\Industry\Application\Double\InMemoryResearchBaseTimes;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectPlanningInputLoader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-1204')]
final class ProjectPlanningInputLoaderCopyAndInventionTest extends TestCase
{
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8057';
    private const string RAITARU_ID = 'raitaru-id';
    private const int HORNET = 2048;
    private const int RAVEN = 638;
    private const int HORNET_II = 2049;
    private const int HORNET_COPY_BASE_SECONDS = 480;
    private const int RAVEN_COPY_BASE_SECONDS = 900;
    private const int ARIA_EVE_CHARACTER_ID = 2_112_000_001;

    public function testCopyLinesAreLoadedWithTheirRunsTheirParentTheirCopyBaseTimeAndTheStructureTimeOfTheirPlannedStructure(): void
    {
        $project = $this->project();
        $input = $this->load($project, [
            $this->bomLine($project, self::HORNET, 'Hornet', 'copy', 10, 31337, self::RAITARU_ID),
            $this->bomLine($project, self::RAVEN, 'Raven', 'copy', 2, null, self::RAITARU_ID),
        ]);

        self::assertSame([
            ['copy', self::HORNET, 'Hornet', 10, 31337, self::HORNET_COPY_BASE_SECONDS, 0.85, true],
            ['copy', self::RAVEN, 'Raven', 2, null, self::RAVEN_COPY_BASE_SECONDS, 0.85, true],
        ], array_map(
            static fn (PlanningLine $line): array => [$line->activity, $line->typeId, $line->typeName, $line->runs, $line->parentTypeId, $line->baseTimeSeconds, $line->structureTimeModifier->value, $line->structureTimeKnown],
            $input->lines,
        ));
    }

    public function testCopyOfATypeWithoutCopyBaseTimeHasNoBaseTime(): void
    {
        $project = $this->project();
        $input = $this->load($project, [$this->bomLine($project, self::HORNET_II, 'Hornet II', 'copy', 4, null, self::RAITARU_ID)]);

        self::assertSame([null], array_map(static fn (PlanningLine $line): ?int => $line->baseTimeSeconds, $input->lines));
    }

    public function testCopyWithoutPlannedStructureIsDurationUnknownAndTheProjectHasNoPlannedEnd(): void
    {
        $project = $this->project();
        $input = $this->load($project, [$this->bomLine($project, self::HORNET, 'Hornet', 'copy', 10, null, null)], $this->aria());

        $planning = (new ProjectPlanningBuilder())->build($input, new \DateTimeImmutable('2026-11-11T08:00:00+00:00'));

        self::assertSame(
            [['Hornet', PlanningUnscheduled::DURATION_UNKNOWN]],
            array_map(static fn (PlanningUnscheduled $line): array => [$line->typeName, $line->reason], $planning->unscheduled),
        );
        self::assertNull($planning->plannedEnd);
    }

    public function testCopyPlannedInAStructureNoLongerConfiguredKeepsTheDurationWithoutStructureBonusAndFlagsIt(): void
    {
        $project = $this->project();
        $input = $this->load($project, [$this->bomLine($project, self::HORNET, 'Hornet', 'copy', 10, null, 'deleted-structure-id')], $this->aria());

        $planning = (new ProjectPlanningBuilder())->build($input, new \DateTimeImmutable('2026-11-11T08:00:00+00:00'));

        self::assertSame(
            [false, 3060],
            [$planning->structureTimeBonusIncluded, null === $planning->plannedEnd ? null : $planning->plannedEnd->getTimestamp() - $planning->anchor->getTimestamp()],
        );
    }

    public function testCopyCoveredByTheStockAtZeroRunsIsLeftOutOfThePlan(): void
    {
        $project = $this->project();
        $input = $this->load($project, [
            $this->bomLine($project, self::HORNET, 'Hornet', 'copy', 0, null, self::RAITARU_ID),
            $this->bomLine($project, self::RAVEN, 'Raven', 'copy', 2, null, self::RAITARU_ID),
        ]);

        self::assertSame(['Raven'], array_map(static fn (PlanningLine $line): string => $line->typeName, $input->lines));
    }

    public function testInventionLinesAreExposedAsLeftOutLinesAndNeverBecomePlanLines(): void
    {
        $project = $this->project();
        $invention = $this->bomLine($project, self::HORNET_II, 'Hornet II', 'invention', 3, null, null);
        $input = $this->load($project, [
            $invention,
            $this->bomLine($project, self::HORNET, 'Hornet', 'copy', 10, null, self::RAITARU_ID),
        ]);

        self::assertEquals(
            [new PlanningLeftOutLine($invention->getId()->toRfc4122(), 'Hornet II')],
            $input->inventionLines,
        );
        self::assertSame(['Hornet'], array_map(static fn (PlanningLine $line): string => $line->typeName, $input->lines));
    }

    public function testInventionCoveredByTheStockAtZeroRunsIsNotListed(): void
    {
        $project = $this->project();
        $input = $this->load($project, [$this->bomLine($project, self::HORNET_II, 'Hornet II', 'invention', 0, null, null)]);

        self::assertSame([], $input->inventionLines);
    }

    public function testProjectWithoutCopyNorInventionHasNoLeftOutLine(): void
    {
        $project = $this->project();
        $input = $this->load($project, [$this->bomLine($project, self::RAVEN, 'Raven', 'manufacturing', 1, null, null)]);

        self::assertSame([], $input->inventionLines);
    }

    public function testCharacterScienceSlotsComeFromTheLaboratoryOperationSkills(): void
    {
        $project = $this->project();
        $input = $this->load($project, [], [
            self::ARIA_EVE_CHARACTER_ID => [
                CachedCharacterSkill::SKILL_MASS_PRODUCTION => 2,
                CachedCharacterSkill::SKILL_MASS_REACTIONS => 1,
                CachedCharacterSkill::SKILL_LABORATORY_OPERATION => 3,
                CachedCharacterSkill::SKILL_ADVANCED_LABORATORY_OPERATION => 1,
            ],
        ]);

        self::assertSame(
            [['Aria Solberg', 3, 2, 5]],
            array_map(static fn (PlanningCharacter $character): array => [$character->name, $character->manufacturingSlots, $character->reactionSlots, $character->scienceSlots], $input->characters),
        );
    }

    public function testCharacterWithoutCachedSkillsHasUnknownScienceSlots(): void
    {
        $project = $this->project();
        $input = $this->load($project, [], [self::ARIA_EVE_CHARACTER_ID => []]);

        self::assertSame(
            [['Aria Solberg', null, null, null]],
            array_map(static fn (PlanningCharacter $character): array => [$character->name, $character->manufacturingSlots, $character->reactionSlots, $character->scienceSlots], $input->characters),
        );
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function aria(): array
    {
        return [self::ARIA_EVE_CHARACTER_ID => [CachedCharacterSkill::SKILL_SCIENCE => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5]];
    }

    private function project(): IndustryProject
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));

        return (new IndustryProject())->setOwner($owner);
    }

    private function bomLine(IndustryProject $project, int $typeId, string $typeName, string $activityType, int $runs, ?int $parentTypeId, ?string $plannedStructureId): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setProject($project)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(1)
            ->setIsJob(true)
            ->setJobGroup('blueprint')
            ->setActivityType($activityType)
            ->setMeLevel(0)
            ->setTeLevel(0)
            ->setRuns($runs)
            ->setParentTypeId($parentTypeId)
            ->setPlannedStructureId($plannedStructureId);
    }

    /**
     * @param list<IndustryProjectBomLine>   $bomLines
     * @param array<int, array<int, int>> $skillLevelsByEveCharacterId
     */
    private function load(IndustryProject $project, array $bomLines, array $skillLevelsByEveCharacterId = []): ProjectPlanningInput
    {
        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findBy')->willReturn($bomLines);

        $characters = [];
        $skillsByEveCharacterId = [];
        foreach ($skillLevelsByEveCharacterId as $eveCharacterId => $levelsBySkillId) {
            $characters[] = (new Character())->setEveCharacterId($eveCharacterId)->setName('Aria Solberg');
            $skills = [];
            foreach ($levelsBySkillId as $skillId => $level) {
                $skills[$skillId] = (new CachedCharacterSkill())->setSkillId($skillId)->setLevel($level);
            }
            $skillsByEveCharacterId[$eveCharacterId] = $skills;
        }
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findByUser')->willReturn($characters);
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            static fn (Character $character): array => $skillsByEveCharacterId[$character->getEveCharacterId()],
        );

        $loader = new ProjectPlanningInputLoader(
            $bomLineRepository,
            $this->createStub(CachedIndustryJobRepository::class),
            $characterRepository,
            $skillRepository,
            InMemoryBlueprintCatalog::fromSdeFixture(),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(IndustryActivitySkillRepository::class),
            new InMemoryUserProductionStructures([self::OWNER_ID => [$this->raitaru()]]),
            new PlannedJobTimeModifier(new InMemoryRigCategories()),
            new InMemoryResearchBaseTimes([
                self::HORNET => [ActivityKind::Copying->name => self::HORNET_COPY_BASE_SECONDS],
                self::RAVEN => [ActivityKind::Copying->name => self::RAVEN_COPY_BASE_SECONDS],
            ]),
        );

        return $loader->load($project);
    }

    private function raitaru(): ProductionStructure
    {
        return new ProductionStructure(
            self::RAITARU_ID,
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            null,
            new \DateTimeImmutable('2026-01-01'),
            Multiplier::one(),
            [],
            Multiplier::one(),
            0.0,
            new Multiplier(0.85),
        );
    }
}
