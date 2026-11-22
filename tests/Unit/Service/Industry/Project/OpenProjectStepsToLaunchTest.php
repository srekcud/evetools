<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\OpenProjectStepsToLaunch;
use App\Service\Industry\Project\ProjectChain;
use App\Service\Industry\Project\ProjectChainLoader;
use App\Service\Industry\Project\ProjectChainNode;
use App\Service\Industry\Project\StepToLaunch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenProjectStepsToLaunch::class)]
#[CoversClass(StepToLaunch::class)]
#[Group('issue-1207')]
final class OpenProjectStepsToLaunchTest extends TestCase
{
    private const int RIFTER_HULL = 1_207_001;
    private const int RIFTER = 1_207_002;
    private const int MAELSTROM_HULL = 1_207_003;
    private const int RIFTER_BLUEPRINT = 1_207_004;
    private const int RIFTER_INVENTION = 1_207_005;
    private const int TRITANIUM = 34;
    private const int FUEL_BLOCK = 1_207_006;

    /** @var list<IndustryProject> */
    private array $openProjects = [];

    /** @var array<string, list<ProjectChainNode>> */
    private array $chainNodesByProjectId = [];

    /** @var list<string> */
    private array $loadedProjectNames = [];

    private OpenProjectStepsToLaunch $openProjectSteps;

    protected function setUp(): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findOpenOfAcceptedParticipant')->willReturnCallback(fn (): array => $this->openProjects);

        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findBy')->willReturn([]);

        $chainLoader = $this->createStub(ProjectChainLoader::class);
        $chainLoader->method('load')->willReturnCallback(function (IndustryProject $project, array $bomLines): ProjectChain {
            $this->loadedProjectNames[] = (string) $project->getName();

            return new ProjectChain($this->chainNodesByProjectId[$project->getId()->toRfc4122()] ?? []);
        });

        $this->openProjectSteps = new OpenProjectStepsToLaunch($projectRepository, $bomLineRepository, $chainLoader);
    }

    public function testProjectsAreReadOldestCreationDateFirst(): void
    {
        $recentProject = $this->openProject('Rifter wave two', ProjectStatus::InProgress, '2026-11-20 10:00:00');
        $oldProject = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$recentProject, $oldProject];
        $this->chainNodesByProjectId[$recentProject->getId()->toRfc4122()] = [$this->todoNode(self::MAELSTROM_HULL, 'Maelstrom Hull', 'manufacturing', 7)];
        $this->chainNodesByProjectId[$oldProject->getId()->toRfc4122()] = [$this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4)];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertCount(2, $steps);
        self::assertSame('Rifter wave one', $steps[0]->projectName);
        self::assertSame($oldProject->getId()->toRfc4122(), $steps[0]->projectId);
        self::assertSame(self::RIFTER_HULL, $steps[0]->typeId);
        self::assertSame('Rifter Hull', $steps[0]->typeName);
        self::assertSame(4, $steps[0]->runs);
        self::assertSame('manufacturing', $steps[0]->slotCategory);
        self::assertSame('Rifter wave two', $steps[1]->projectName);
        self::assertSame(self::MAELSTROM_HULL, $steps[1]->typeId);
        self::assertSame(7, $steps[1]->runs);
    }

    public function testStepsOfAProjectKeepTheChainOrder(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4),
            $this->todoNode(self::FUEL_BLOCK, 'Helium Fuel Block', 'reaction', 3, stage: 'component'),
            $this->todoNode(self::RIFTER, 'Rifter', 'manufacturing', 11, stage: 'product'),
        ];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertSame([self::RIFTER_HULL, self::FUEL_BLOCK, self::RIFTER], array_map(static fn (StepToLaunch $step): int => $step->typeId, $steps));
        self::assertSame(['manufacturing', 'reaction', 'manufacturing'], array_map(static fn (StepToLaunch $step): string => $step->slotCategory, $steps));
        self::assertSame([4, 3, 11], array_map(static fn (StepToLaunch $step): int => $step->runs, $steps));
    }

    public function testDraftProjectIsIgnored(): void
    {
        $draftProject = $this->openProject('Rifter draft', ProjectStatus::Draft, '2026-11-10 10:00:00');
        $this->openProjects = [$draftProject];
        $this->chainNodesByProjectId[$draftProject->getId()->toRfc4122()] = [$this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4)];

        self::assertSame([], $this->openProjectSteps->forUser(new User()));
    }

    public function testDraftProjectChainIsNotLoadedAndOtherProjectsAreStillRead(): void
    {
        $draftProject = $this->openProject('Rifter draft', ProjectStatus::Draft, '2026-11-10 10:00:00');
        $publishedProject = $this->openProject('Rifter published', ProjectStatus::Published, '2026-11-12 10:00:00');
        $this->openProjects = [$draftProject, $publishedProject];
        $this->chainNodesByProjectId[$draftProject->getId()->toRfc4122()] = [$this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4)];
        $this->chainNodesByProjectId[$publishedProject->getId()->toRfc4122()] = [$this->todoNode(self::MAELSTROM_HULL, 'Maelstrom Hull', 'manufacturing', 6)];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertSame(['Rifter published'], $this->loadedProjectNames);
        self::assertCount(1, $steps);
        self::assertSame(self::MAELSTROM_HULL, $steps[0]->typeId);
    }

    public function testNodesThatAreNotTodoAreNotSteps(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4, status: 'blocked'),
            $this->todoNode(self::MAELSTROM_HULL, 'Maelstrom Hull', 'manufacturing', 5, status: 'missing_materials'),
            $this->todoNode(self::FUEL_BLOCK, 'Helium Fuel Block', 'reaction', 6, status: 'active'),
            $this->todoNode(self::RIFTER, 'Rifter', 'manufacturing', 7, status: 'done'),
            $this->todoNode(self::RIFTER_BLUEPRINT, 'Rifter Blueprint', 'copy', 8, stage: 'blueprint', status: 'unknown'),
        ];

        self::assertSame([], $this->openProjectSteps->forUser(new User()));
    }

    public function testCopyAndInventionStepsBelongToTheScienceSlotCategory(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::RIFTER_BLUEPRINT, 'Rifter Blueprint', 'copy', 20, stage: 'blueprint'),
            $this->todoNode(self::RIFTER_INVENTION, 'Rifter Invention', 'invention', 9, stage: 'blueprint'),
        ];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertCount(2, $steps);
        self::assertSame('science', $steps[0]->slotCategory);
        self::assertSame(self::RIFTER_BLUEPRINT, $steps[0]->typeId);
        self::assertSame(20, $steps[0]->runs);
        self::assertSame('science', $steps[1]->slotCategory);
        self::assertSame(self::RIFTER_INVENTION, $steps[1]->typeId);
        self::assertSame(9, $steps[1]->runs);
    }

    public function testNodeOfAnUnknownActivityIsIgnoredWithoutException(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::RIFTER_BLUEPRINT, 'Rifter Blueprint', 'research_me', 12, stage: 'blueprint'),
            $this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4),
        ];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertCount(1, $steps);
        self::assertSame(self::RIFTER_HULL, $steps[0]->typeId);
    }

    public function testMaterialAndSaleNodesAreNotSteps(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::TRITANIUM, 'Tritanium', 'manufacturing', 13, stage: 'material'),
            $this->todoNode(self::RIFTER, 'Rifter', 'manufacturing', 14, stage: 'sale'),
        ];

        self::assertSame([], $this->openProjectSteps->forUser(new User()));
    }

    public function testNodeWithoutTypeNameIsIgnored(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::MAELSTROM_HULL, null, 'manufacturing', 5),
            $this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4),
        ];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertCount(1, $steps);
        self::assertSame('Rifter Hull', $steps[0]->typeName);
    }

    public function testProjectWithoutJobNodeYieldsNoStep(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $emptyProject = $this->openProject('Rifter empty', ProjectStatus::InProgress, '2026-11-18 10:00:00');
        $this->openProjects = [$project, $emptyProject];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::TRITANIUM, 'Tritanium', null, null, stage: 'material'),
        ];

        self::assertSame([], $this->openProjectSteps->forUser(new User()));
    }

    public function testJobNodeWithoutRunsRaisesAnExplicitError(): void
    {
        $project = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $this->openProjects = [$project];
        $this->chainNodesByProjectId[$project->getId()->toRfc4122()] = [
            $this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', null),
        ];

        $this->expectException(\UnexpectedValueException::class);

        $this->openProjectSteps->forUser(new User());
    }

    public function testSameTypeInTwoProjectsGivesTwoDistinctSteps(): void
    {
        $firstProject = $this->openProject('Rifter wave one', ProjectStatus::InProgress, '2026-11-17 10:00:00');
        $secondProject = $this->openProject('Rifter wave two', ProjectStatus::InProgress, '2026-11-18 10:00:00');
        $this->openProjects = [$secondProject, $firstProject];
        $this->chainNodesByProjectId[$firstProject->getId()->toRfc4122()] = [$this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 4)];
        $this->chainNodesByProjectId[$secondProject->getId()->toRfc4122()] = [$this->todoNode(self::RIFTER_HULL, 'Rifter Hull', 'manufacturing', 9)];

        $steps = $this->openProjectSteps->forUser(new User());

        self::assertSame([4, 9], array_map(static fn (StepToLaunch $step): int => $step->runs, $steps));
        self::assertSame(['Rifter wave one', 'Rifter wave two'], array_map(static fn (StepToLaunch $step): string => $step->projectName, $steps));
    }

    private function openProject(string $name, ProjectStatus $status, string $createdAt): IndustryProject
    {
        $project = (new IndustryProject())->setName($name)->setStatus($status);
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable($createdAt.' +00:00'));

        return $project;
    }

    private function todoNode(
        int $typeId,
        ?string $typeName,
        ?string $activity,
        ?int $runs,
        string $stage = 'product',
        string $status = 'todo',
    ): ProjectChainNode {
        return new ProjectChainNode(
            id: 'node-'.$typeId,
            stage: $stage,
            parentId: null,
            typeId: $typeId,
            typeName: $typeName,
            activity: $activity,
            runs: $runs,
            meLevel: null,
            teLevel: null,
            requiredQuantity: null,
            fulfilledQuantity: null,
            status: $status,
            costState: 'none',
            realCost: null,
            suppliers: [],
            jobs: [],
            structures: [],
            plannedStructure: null,
            soldQuantity: null,
            grossRevenue: null,
            level: 0,
        );
    }
}
