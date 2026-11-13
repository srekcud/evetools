<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\ProjectChainResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Industry\Application\PlannedCopyDurations;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Project\ChainBlueprintNames;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\PlanningBlueprintNames;
use App\Service\Industry\Project\ProjectChainBuilder;
use App\Service\Industry\Project\ProjectChainNode;
use App\Service\Industry\Project\ProjectJobMaterialNeedsLoader;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectChainProvider;
use App\Tests\Unit\Industry\Application\Double\InMemoryCharacterSkillLevels;
use App\Tests\Unit\Industry\Application\Double\InMemoryResearchBaseTimes;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
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
    private const string OWNER_ID = '01970000-0000-7000-8000-0000000000f2';
    private const string AZBEL_ID = '01970000-0000-7000-8000-0000000000b1';
    private const int ARIA_EVE_CHARACTER_ID = 2_120_000_001;
    private const int ARIA_COPIER_EVE_CHARACTER_ID = 2_120_000_003;
    private const int BRANN_EVE_CHARACTER_ID = 2_120_000_011;
    private const int ELIA_EVE_CHARACTER_ID = 2_120_000_021;
    private const int SCIENCE = 3402;
    private const int ADVANCED_INDUSTRY = 3388;
    private const int ISHTAR = 12005;
    private const int NOCXIUM_ATOMS = 16637;
    private const int RAW_SPECIMEN = 99001;
    private const int ISHTAR_COPY_BASE_TIME_SECONDS = 18_000;
    private const int ISHTAR_COPY_RUNS = 3;
    private const float AZBEL_TIME_ROLE_BONUS = 0.80;
    private const float AZBEL_COST_ROLE_BONUS = 0.96;

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectParticipantRepository&Stub $participantRepository;
    private IndustryProjectBomLineRepository&MockObject $bomLineRepository;
    private IndustryProjectContributionRepository&MockObject $contributionRepository;
    private IndustryProjectSaleRepository&MockObject $saleRepository;
    private IndustryStructureConfigRepository&MockObject $structureConfigRepository;
    private IndustryProjectService&Stub $projectService;
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
        $this->projectService = $this->createStub(IndustryProjectService::class);
        $this->projectService->method('planOf')->willThrowException(new \RuntimeException('No blueprint or reaction found for product type ID 337002'));

        $this->owner = $this->pilot('Aria Solberg', self::ARIA_EVE_CHARACTER_ID);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->owner, Uuid::fromString(self::OWNER_ID));
        $this->member = $this->pilot('Brann Holt', self::BRANN_EVE_CHARACTER_ID);
        $this->outsider = $this->pilot('Elia Varr', self::ELIA_EVE_CHARACTER_ID);
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

    public function testJobsThatWouldBeToLaunchAreUnknownWhenThePlanOfTheProjectCannotBeComputed(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $job = $this->jobLine(449_001, 'Hammerhead II', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $this->bomLineRepository->method('findBy')->willReturn([$job]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);

        $chain = $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        self::assertSame('unknown', $chain->nodes[0]->status);
    }

    public function testJobWhoseMaterialsAreNotFulfilledIsMissingMaterialsFromThePlanOfTheOwner(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $job = $this->jobLine(449_001, 'Hammerhead II', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $tritanium = $this->materialLine();
        $planJob = new Job(449_001, ActivityKind::Manufacturing, new Runs(10), new MaterialEfficiency(0), new TimeEfficiency(0), [34 => new Quantity(1_000)]);
        $projectService = $this->createMock(IndustryProjectService::class);
        $projectService->expects(self::once())->method('planOf')->with($this->project, $this->owner)->willReturn(new PlannedProduction(new Plan([$planJob], [], []), []));
        $this->bomLineRepository->method('findBy')->willReturn([$job, $tritanium]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);
        $provider = new ProjectChainProvider(
            $this->security,
            $this->projectRepository,
            $this->bomLineRepository,
            $this->contributionRepository,
            $this->saleRepository,
            $this->structureConfigRepository,
            new ProjectAccessChecker($this->participantRepository),
            $this->chainBuilder(),
            new ProjectJobMaterialNeedsLoader($projectService),
            $this->blueprintNames(),
        );

        $chain = $provider->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        self::assertSame('missing_materials', $this->node($chain, $job->getId()->toRfc4122())->status);
    }

    #[Group('issue-339')]
    public function testJobsShowTheMainCharacterOfTheOwnerToAMemberAndTheAltToTheOwner(): void
    {
        $alt = (new Character())->setEveCharacterId(2120000002)->setName('Aria Alt');
        $this->owner->addCharacter($alt);
        $job = $this->jobLine(449_001, 'Hammerhead II', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $job->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(1)->setStatus('active')->setRuns(10)->setCharacterName('Aria Alt'));
        $this->bomLineRepository->method('findBy')->willReturn([$job]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);
        $installersByViewer = [];

        foreach ([$this->member, $this->owner] as $viewer) {
            $security = $this->createStub(Security::class);
            $security->method('getUser')->willReturn($viewer);
            $provider = new ProjectChainProvider(
                $security,
                $this->projectRepository,
                $this->bomLineRepository,
                $this->contributionRepository,
                $this->saleRepository,
                $this->structureConfigRepository,
                new ProjectAccessChecker($this->participantRepository),
                $this->chainBuilder(),
                new ProjectJobMaterialNeedsLoader($this->projectService),
                $this->blueprintNames(),
            );
            $chain = $provider->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);
            $installersByViewer[] = array_column($this->node($chain, $job->getId()->toRfc4122())->jobs, 'characterName');
        }

        self::assertSame([['Aria Solberg'], ['Aria Alt']], $installersByViewer);
    }

    #[Group('issue-524')]
    public function testPlannedCopyDurationNamesTheCopierToTheOwnerAndTheMainCharacterOfTheOwnerToAMember(): void
    {
        $this->owner->addCharacter((new Character())->setEveCharacterId(self::ARIA_COPIER_EVE_CHARACTER_ID)->setName('Aria Copier'));
        $copy = $this->jobLine(self::ISHTAR, 'Ishtar', 'copy', self::AZBEL_ID, 'best_configured');
        $copy->setJobGroup('blueprint')->setRuns(self::ISHTAR_COPY_RUNS)->setRequiredQuantity(self::ISHTAR_COPY_RUNS);
        $this->bomLineRepository->method('findBy')->willReturn([$copy]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);
        $durationsByViewer = [];

        foreach ([$this->member, $this->owner] as $viewer) {
            $security = $this->createStub(Security::class);
            $security->method('getUser')->willReturn($viewer);
            $provider = new ProjectChainProvider(
                $security,
                $this->projectRepository,
                $this->bomLineRepository,
                $this->contributionRepository,
                $this->saleRepository,
                $this->structureConfigRepository,
                new ProjectAccessChecker($this->participantRepository),
                $this->chainBuilder(),
                new ProjectJobMaterialNeedsLoader($this->projectService),
                $this->blueprintNames(),
            );
            $copyNode = $this->node($provider->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]), $copy->getId()->toRfc4122());
            self::assertObjectHasProperty('plannedCopyDuration', $copyNode);
            $durationsByViewer[] = $copyNode->plannedCopyDuration;
        }

        self::assertSame(
            [
                ['seconds' => 28_512, 'missingData' => [], 'characterName' => 'Aria Solberg'],
                ['seconds' => 28_512, 'missingData' => [], 'characterName' => 'Aria Copier'],
            ],
            $durationsByViewer,
        );
    }

    #[Group('issue-906')]
    public function testJobNodesCarryTheNameOfTheBlueprintOrFormulaThatBuildsThemAndOtherNodesCarryNone(): void
    {
        $this->security->method('getUser')->willReturn($this->member);
        $manufacturing = $this->jobLine(self::ISHTAR, 'Ishtar', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $reaction = $this->jobLine(self::NOCXIUM_ATOMS, 'Nocxium Atoms', 'reaction', Uuid::v7()->toRfc4122(), 'best_configured');
        $unknownProducer = $this->jobLine(self::RAW_SPECIMEN, 'Raw Specimen', 'manufacturing', Uuid::v7()->toRfc4122(), 'best_configured');
        $tritanium = $this->materialLine();
        $this->bomLineRepository->method('findBy')->willReturn([$manufacturing, $reaction, $unknownProducer, $tritanium]);
        $this->contributionRepository->method('findApprovedByProject')->willReturn([]);
        $this->saleRepository->method('findByProjectForListing')->willReturn([]);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);

        $chain = $this->provider()->provide(new Get(), ['projectId' => $this->project->getId()->toRfc4122()]);

        $expected = [
            $manufacturing->getId()->toRfc4122() => 'Ishtar Blueprint',
            $reaction->getId()->toRfc4122() => 'Nocxium Atoms Reaction Formula',
            $unknownProducer->getId()->toRfc4122() => null,
            $tritanium->getId()->toRfc4122() => null,
            'sale' => null,
        ];
        $actual = [];
        foreach ($chain->nodes as $node) {
            $actual[$node->id] = $node->blueprintName;
        }
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    private function blueprintNames(): ChainBlueprintNames
    {
        $products = $this->createStub(IndustryActivityProductRepository::class);
        $products->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId): ?IndustryActivityProduct => match ([$productTypeId, $activityId]) {
                [self::ISHTAR, 1] => (new IndustryActivityProduct())->setTypeId(12_006)->setActivityId(1)->setProductTypeId(self::ISHTAR)->setQuantity(1),
                [self::NOCXIUM_ATOMS, 11] => (new IndustryActivityProduct())->setTypeId(46_170)->setActivityId(11)->setProductTypeId(self::NOCXIUM_ATOMS)->setQuantity(1),
                default => null,
            },
        );
        $types = $this->createStub(InvTypeRepository::class);
        $types->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds): array {
                $known = [12_006 => 'Ishtar Blueprint', 46_170 => 'Nocxium Atoms Reaction Formula'];
                $found = [];
                foreach ($typeIds as $typeId) {
                    if (isset($known[$typeId])) {
                        $found[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($known[$typeId]);
                    }
                }

                return $found;
            },
        );

        return new ChainBlueprintNames(new PlanningBlueprintNames($products, $types));
    }

    private function chainBuilder(): ProjectChainBuilder
    {
        return new ProjectChainBuilder(new PlannedCopyDurations(
            new InMemoryResearchBaseTimes([self::ISHTAR => [ActivityKind::Copying->name => self::ISHTAR_COPY_BASE_TIME_SECONDS]]),
            new InMemoryCharacterSkillLevels([
                self::ARIA_EVE_CHARACTER_ID => [self::SCIENCE => 2, self::ADVANCED_INDUSTRY => 1],
                self::ARIA_COPIER_EVE_CHARACTER_ID => [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 4],
            ]),
            new InMemoryUserProductionStructures([self::OWNER_ID => [new ProductionStructure(
                self::AZBEL_ID,
                StructureType::EngineeringComplex,
                SecurityClass::LowSec,
                null,
                new \DateTimeImmutable('2026-01-01 00:00:00'),
                new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
                [],
                new Multiplier(self::AZBEL_COST_ROLE_BONUS),
                ProductionStructures::NO_FACILITY_TAX,
                timeRoleBonus: new Multiplier(self::AZBEL_TIME_ROLE_BONUS),
            )]]),
        ));
    }

    private function node(ProjectChainResource $chain, string $id): ProjectChainNode
    {
        foreach ($chain->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        self::fail(\sprintf('The chain has no node %s.', $id));
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
            $this->chainBuilder(),
            new ProjectJobMaterialNeedsLoader($this->projectService),
            $this->blueprintNames(),
        );
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

    private function pilot(string $name, int $eveCharacterId): User
    {
        $character = (new Character())->setName($name)->setEveCharacterId($eveCharacterId);
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
