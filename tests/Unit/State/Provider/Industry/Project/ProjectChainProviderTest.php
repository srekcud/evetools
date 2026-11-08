<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\ProjectChainResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Industry\Application\PlannedProduction;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectChainBuilder;
use App\Service\Industry\Project\ProjectChainLevels;
use App\Service\Industry\Project\ProjectChainNode;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectChainProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectChainProvider::class)]
#[Group('issue-337')]
final class ProjectChainProviderTest extends TestCase
{
    private const string NOT_A_UUID = 'abc';
    private const string OSTINGELE_RAITARU_NAME = 'Ostingele - Raitaru of the Hammerheads';
    private const string TATARA_NAME = 'Ostingele - Tatara of the Fullerides';

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectParticipantRepository&Stub $participantRepository;
    private IndustryProjectBomLineRepository&MockObject $bomLineRepository;
    private IndustryProjectContributionRepository&MockObject $contributionRepository;
    private IndustryProjectSaleRepository&MockObject $saleRepository;
    private IndustryStructureConfigRepository&MockObject $structureConfigRepository;
    private IndustryProjectService&MockObject $projectService;
    private ?PlannedProduction $plannedProduction = null;
    private User $owner;
    private User $member;
    private User $outsider;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->participantRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->bomLineRepository = $this->createMock(IndustryProjectBomLineRepository::class);
        $this->contributionRepository = $this->createMock(IndustryProjectContributionRepository::class);
        $this->saleRepository = $this->createMock(IndustryProjectSaleRepository::class);
        $this->structureConfigRepository = $this->createMock(IndustryStructureConfigRepository::class);
        $this->projectService = $this->createMock(IndustryProjectService::class);
        $this->projectService->method('planOf')->willReturnCallback(
            fn (IndustryProject $project, User $owner): PlannedProduction => $this->plannedProduction ?? throw new \RuntimeException('The plan cannot be computed.'),
        );

        $this->owner = $this->pilot('Aria Solberg');
        $this->member = $this->pilot('Brann Holt');
        $this->outsider = $this->pilot('Elia Varr');
        $this->project = (new IndustryProject())->setOwner($this->owner)->setName('Hammerhead batch');
        $memberParticipant = (new IndustryProjectParticipant())->setUser($this->member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($memberParticipant);

        $this->projectRepository->method('find')->willReturnCallback(
            fn (mixed $id): ?IndustryProject => $id instanceof Uuid && $id->equals($this->project->getId()) ? $this->project : null,
        );
        $this->participantRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectParticipant => ($criteria['user'] ?? null) === $this->member ? $memberParticipant : null,
        );
    }

    public function testChainOfAnUnknownProjectIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->provider()->provide(new Get(), ['projectId' => Uuid::v4()->toRfc4122()]);
    }

    public function testChainOfAProjectIdentifierThatIsNotAUuidIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->provider()->provide(new Get(), ['projectId' => self::NOT_A_UUID]);
    }

    public function testPilotWhoIsNotAParticipantIsRefusedTheChainBeforeAnyRead(): void
    {
        $this->security->method('getUser')->willReturn($this->outsider);
        $this->bomLineRepository->expects(self::never())->method(self::anything());
        $this->contributionRepository->expects(self::never())->method(self::anything());
        $this->saleRepository->expects(self::never())->method(self::anything());
        $this->structureConfigRepository->expects(self::never())->method(self::anything());

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    public function testChainReadsTheBomTheApprovedContributionsTheSalesAndTheOwnerStructuresOnceEach(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $tritanium = $this->materialLine();
        $this->bomLineRepository->expects(self::once())->method('findBy')->with(['project' => $this->project])->willReturn([$tritanium]);
        $this->contributionRepository->expects(self::once())->method('findApprovedByProject')->with($this->project)->willReturn([]);
        $this->saleRepository->expects(self::once())->method('findByProjectForListing')->with($this->project)->willReturn([]);
        $this->structureConfigRepository->expects(self::once())->method('findByUser')->with($this->owner)->willReturn([]);

        $chain = $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        self::assertInstanceOf(ProjectChainResource::class, $chain);
        self::assertSame(
            [$this->project->getId()->toRfc4122(), [$tritanium->getId()->toRfc4122(), 'sale']],
            [$chain->id, array_map(static fn (ProjectChainNode $node): string => $node->id, $chain->nodes)],
        );
    }

    public function testPlannedStructuresAreNamedFromTheConfigurationsOfTheOwnerAndADeletedOneHasNoName(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $raitaru = $this->structureConfiguration(self::OSTINGELE_RAITARU_NAME);
        $tatara = $this->structureConfiguration(self::TATARA_NAME);
        $plannedInTheRaitaru = $this->jobLine(337_002, 'Hammerhead II', 'manufacturing', $raitaru->getId()->toRfc4122(), 'best_in_favorite_system');
        $plannedInADeletedStructure = $this->jobLine(337_005, 'Fullerides', 'reaction', Uuid::v7()->toRfc4122(), 'best_configured');
        $this->bomLineRepository->method('findBy')->willReturn([$plannedInTheRaitaru, $plannedInADeletedStructure]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturnCallback(
            fn (User $user): array => $user === $this->owner ? [$raitaru, $tatara] : [],
        );

        $chain = $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        self::assertSame(
            [
                $plannedInTheRaitaru->getId()->toRfc4122() => ['name' => self::OSTINGELE_RAITARU_NAME, 'status' => 'best_in_favorite_system'],
                $plannedInADeletedStructure->getId()->toRfc4122() => ['name' => null, 'status' => 'best_configured'],
            ],
            $this->plannedStructuresByNodeId($chain),
        );
    }

    #[Group('issue-418')]
    public function testJobNodesAreLeveledFromTheOwnerPlan(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $hammerheadII = $this->jobLine(337_002, 'Hammerhead II', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $hammerheadI = $this->jobLine(337_001, 'Hammerhead I', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $this->bomLineRepository->method('findBy')->willReturn([$hammerheadII, $hammerheadI, $this->materialLine()]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);
        $this->plannedProduction = new PlannedProduction(new Plan([
            $this->planJob(337_002, [337_001, 34]),
            $this->planJob(337_001, [34]),
        ], [], []), []);

        $chain = $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        self::assertSame(
            [$hammerheadI->getId()->toRfc4122() => 1, $hammerheadII->getId()->toRfc4122() => 2],
            $this->levelsByNodeId($chain, [$hammerheadI, $hammerheadII]),
        );
    }

    #[Group('issue-418')]
    public function testChainIsStillServedWithUnknownLevelsWhenThePlanCannotBeComputed(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $hammerheadII = $this->jobLine(337_002, 'Hammerhead II', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $this->bomLineRepository->method('findBy')->willReturn([$hammerheadII]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);

        $chain = $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        self::assertSame([$hammerheadII->getId()->toRfc4122() => null], $this->levelsByNodeId($chain, [$hammerheadII]));
    }

    #[Group('issue-418')]
    public function testPlanIsComputedForTheProjectOwnerNotForTheReadingMember(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $this->bomLineRepository->method('findBy')->willReturn([]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);
        $this->projectService = $this->createMock(IndustryProjectService::class);
        $this->projectService->expects(self::once())->method('planOf')->with($this->project, $this->owner)->willThrowException(new \RuntimeException('no plan'));

        $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function provider(): ProjectChainProvider
    {
        return new ProjectChainProvider(
            $this->security,
            $this->projectRepository,
            $this->bomLineRepository,
            $this->contributionRepository,
            $this->saleRepository,
            $this->structureConfigRepository,
            new ProjectAccessChecker($this->participantRepository),
            new ProjectChainBuilder(),
            $this->projectService,
            new ProjectChainLevels(),
        );
    }

    /**
     * @param list<int> $materialTypeIds
     */
    private function planJob(int $productTypeId, array $materialTypeIds): Job
    {
        $materials = [];
        foreach ($materialTypeIds as $materialTypeId) {
            $materials[$materialTypeId] = new Quantity(10);
        }

        return new Job($productTypeId, ActivityKind::Manufacturing, new Runs(1), new MaterialEfficiency(0), new TimeEfficiency(0), $materials);
    }

    /**
     * @param list<IndustryProjectBomLine> $lines
     * @return array<string, int|null>
     */
    private function levelsByNodeId(ProjectChainResource $chain, array $lines): array
    {
        $levelByNodeId = [];
        foreach ($chain->nodes as $node) {
            $levelByNodeId[$node->id] = $node->level;
        }

        $levels = [];
        foreach ($lines as $line) {
            $levels[$line->getId()->toRfc4122()] = $levelByNodeId[$line->getId()->toRfc4122()];
        }

        return $levels;
    }

    private function plannedStructuresByNodeId(ProjectChainResource $chain): array
    {
        $plannedStructures = [];
        foreach ($chain->nodes as $node) {
            if ($node->stage !== 'sale') {
                $plannedStructures[$node->id] = $node->plannedStructure;
            }
        }
        ksort($plannedStructures);

        return $plannedStructures;
    }

    private function pilot(string $name): User
    {
        $character = (new Character())->setName($name);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function materialLine(): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $line->setProject($this->project);

        return $line;
    }

    private function jobLine(int $typeId, string $typeName, string $activityType, string $plannedStructureId, string $plannedStructureStatus): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(10)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType($activityType)
            ->setRuns(10)
            ->setMeLevel(0)
            ->setTeLevel(0)
            ->setPlannedStructureId($plannedStructureId)
            ->setPlannedStructureStatus($plannedStructureStatus);
        $line->setProject($this->project);

        return $line;
    }

    private function structureConfiguration(string $name): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())->setUser($this->owner)->setName($name);
    }
}
