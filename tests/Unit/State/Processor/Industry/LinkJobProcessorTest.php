<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Processor\Industry\LinkJobProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ownership rule: a user links to his project steps only industry jobs installed by one of
 * HIS characters. Corporation jobs are cached with the installer as `character`, so the rule
 * is the same for them. Any other job id is answered exactly like a job that does not exist.
 */
#[CoversClass(LinkJobProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class LinkJobProcessorTest extends TestCase
{
    private const int BLUEPRINT_TYPE_ID = 1_000_001;
    private const int OTHER_BLUEPRINT_TYPE_ID = 1_000_002;
    private const int ESI_JOB_ID = 600_000_001;
    private const int UNKNOWN_ESI_JOB_ID = 600_000_999;
    private const int STEP_RUNS = 10;
    private const float JOB_COST = 1_234_567.89;
    private const int MANUFACTURING_BLUEPRINT_TYPE_ID = 1001;
    private const int REACTION_FORMULA_TYPE_ID = 2001;
    private const int CANCELLED_JOB_ID = 300;
    private const int REVERTED_JOB_ID = 301;
    private const int ACTIVE_JOB_ID = 302;
    private const int DELIVERED_JOB_ID = 305;
    private const float SPEC_JOB_COST = 1_250_000.75;
    private const int SPEC_JOB_RUNS = 10;
    private const int RAITARU_LOCATION_ID = 60001;
    private const int AZBEL_LOCATION_ID = 60002;
    private const float RAITARU_MATERIAL_BONUS = 2.1;
    private const string AZBEL_FACILITY_NAME = 'Azbel facility 60002';
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_RESEARCH_TIME = 3;
    private const int ACTIVITY_COPY = 5;
    private const int ACTIVITY_INVENTION = 8;
    private const int ACTIVITY_REACTION_ESI = 9;
    private const int ACTIVITY_REACTION_SDE = 11;

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectStepRepository&Stub $stepRepository;
    private CachedIndustryJobRepository&Stub $jobRepository;
    private IndustryStructureConfigRepository&Stub $structureConfigRepository;
    private IndustryCalculationService&Stub $calculationService;
    private IndustryResourceMapper&Stub $mapper;
    private EntityManagerInterface&MockObject $entityManager;
    private LinkJobProcessor $processor;

    private User $user;
    private Character $mainCharacter;
    private IndustryProject $project;
    private IndustryProjectStep $step;
    private ProjectStepResource $stepResource;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $this->jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $this->structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $this->calculationService = $this->createStub(IndustryCalculationService::class);
        $this->mapper = $this->createStub(IndustryResourceMapper::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->processor = $this->createProcessor();

        $this->user = new User();
        $this->mainCharacter = $this->createCharacter($this->user, 'Main Pilot');
        $this->security->method('getUser')->willReturn($this->user);

        $this->project = (new IndustryProject())->setUser($this->user);
        $this->projectRepository->method('find')->willReturn($this->project);

        $this->step = (new IndustryProjectStep())
            ->setProject($this->project)
            ->setBlueprintTypeId(self::BLUEPRINT_TYPE_ID)
            ->setRuns(self::STEP_RUNS)
            ->setQuantity(self::STEP_RUNS);
        $this->stepRepository->method('find')->willReturn($this->step);

        $this->stepResource = new ProjectStepResource();
        $this->mapper->method('stepToResource')->willReturn($this->stepResource);

        // No other step already holds the job.
        $query = $this->createStub(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getOneOrNullResult')->willReturn(null);
        $this->entityManager->method('createQuery')->willReturn($query);
    }

    // ===========================================
    // Linking a job of one's own characters
    // ===========================================

    public function testLinksJobInstalledByOwnCharacterAndCopiesItsData(): void
    {
        $this->givenJob($this->mainCharacter);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->link(self::ESI_JOB_ID);

        self::assertSame($this->stepResource, $result);
        $match = $this->onlyJobMatch();
        self::assertSame(self::ESI_JOB_ID, $match->getEsiJobId());
        self::assertSame(self::JOB_COST, $match->getCost());
        self::assertSame(self::STEP_RUNS, $match->getRuns());
        self::assertSame('active', $match->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-10-02 12:00:00'), $match->getEndDate());
        self::assertSame('Main Pilot', $match->getCharacterName());
        self::assertSame(self::STEP_RUNS, $this->step->getRuns());
    }

    public function testLinksCorporationJobInstalledByOwnAlt(): void
    {
        // Corporation jobs are cached with their installer as character.
        $alt = $this->createCharacter($this->user, 'Alt Pilot');
        $this->givenJob($alt);
        $this->entityManager->expects($this->once())->method('flush');

        $this->link(self::ESI_JOB_ID);

        self::assertSame('Alt Pilot', $this->onlyJobMatch()->getCharacterName());
    }

    // ===========================================
    // Job installed by someone else's character
    // ===========================================

    public function testJobOfAnotherUsersCharacterIsAnsweredLikeAnUnknownJob(): void
    {
        $this->givenJob($this->createCharacter(new User(), 'Stranger Pilot'));

        $foreignJobError = $this->captureLinkError(self::ESI_JOB_ID);
        $unknownJobError = $this->captureLinkError(self::UNKNOWN_ESI_JOB_ID);

        self::assertSame($unknownJobError::class, $foreignJobError::class);
        self::assertSame($unknownJobError->getStatusCode(), $foreignJobError->getStatusCode());
        self::assertSame(
            str_replace((string) self::UNKNOWN_ESI_JOB_ID, (string) self::ESI_JOB_ID, $unknownJobError->getMessage()),
            $foreignJobError->getMessage(),
        );
    }

    public function testJobOfAnotherUsersCharacterIsNotFoundAndNothingIsPersisted(): void
    {
        $this->givenJob($this->createCharacter(new User(), 'Stranger Pilot'));
        $this->entityManager->expects($this->never())->method('flush');

        $error = $this->captureLinkError(self::ESI_JOB_ID);

        self::assertSame(400, $error->getStatusCode());
        self::assertSame('ESI job 600000001 not found', $error->getMessage());
        self::assertCount(0, $this->step->getJobMatches());
        self::assertSame(self::STEP_RUNS, $this->step->getRuns());
    }

    public function testJobOfAnotherUsersCharacterWithOtherBlueprintIsNotFoundEither(): void
    {
        // The blueprint check must not reveal that the job exists.
        $this->givenJob($this->createCharacter(new User(), 'Stranger Pilot'), self::OTHER_BLUEPRINT_TYPE_ID);
        $this->entityManager->expects($this->never())->method('flush');

        $error = $this->captureLinkError(self::ESI_JOB_ID);

        self::assertSame(400, $error->getStatusCode());
        self::assertSame('ESI job 600000001 not found', $error->getMessage());
    }

    public function testJobOfCharacterWithoutUserIsNotFound(): void
    {
        $this->givenJob($this->createCharacter(null, 'Orphan Pilot'));
        $this->entityManager->expects($this->never())->method('flush');

        $error = $this->captureLinkError(self::ESI_JOB_ID);

        self::assertSame(400, $error->getStatusCode());
        self::assertSame('ESI job 600000001 not found', $error->getMessage());
        self::assertCount(0, $this->step->getJobMatches());
    }

    // ===========================================
    // Existing rules (guards)
    // ===========================================

    public function testUnknownJobIsNotFound(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $error = $this->captureLinkError(self::UNKNOWN_ESI_JOB_ID);

        self::assertSame(400, $error->getStatusCode());
        self::assertSame('ESI job 600000999 not found', $error->getMessage());
    }

    public function testOwnJobWithOtherBlueprintIsRejected(): void
    {
        $this->givenJob($this->mainCharacter, self::OTHER_BLUEPRINT_TYPE_ID);
        $this->entityManager->expects($this->never())->method('flush');

        $error = $this->captureLinkError(self::ESI_JOB_ID);

        self::assertSame(400, $error->getStatusCode());
        self::assertSame('Job blueprint does not match step blueprint', $error->getMessage());
    }

    public function testRequiresAnAuthenticatedUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $processor = $this->createProcessor(security: $security);

        $this->expectException(UnauthorizedHttpException::class);

        $processor->process($this->input(self::ESI_JOB_ID), new Post(), $this->uriVariables());
    }

    public function testProjectOfAnotherUserIsNotFound(): void
    {
        $this->project->setUser(new User());
        $this->givenJob($this->mainCharacter);
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->link(self::ESI_JOB_ID);
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn(null);
        $processor = $this->createProcessor(projectRepository: $projectRepository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $processor->process($this->input(self::ESI_JOB_ID), new Post(), $this->uriVariables());
    }

    public function testStepOfAnotherProjectIsNotFound(): void
    {
        $this->step->setProject((new IndustryProject())->setUser($this->user));
        $this->givenJob($this->mainCharacter);
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $this->link(self::ESI_JOB_ID);
    }

    public function testUnknownStepIsNotFound(): void
    {
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn(null);
        $processor = $this->createProcessor(stepRepository: $stepRepository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $processor->process($this->input(self::ESI_JOB_ID), new Post(), $this->uriVariables());
    }

    // ===========================================
    // Progress (issue #3)
    // ===========================================

    #[Group('issue-3')]
    public function testManualLinkOfPartialJobKeepsStepRunsAndQuantityWithoutRecalculation(): void
    {
        $this->givenJob($this->mainCharacter, runs: 5);
        $stepCalculator = $this->createMock(IndustryStepCalculator::class);
        $stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->createProcessor(stepCalculator: $stepCalculator)
            ->process($this->input(self::ESI_JOB_ID), new Post(), $this->uriVariables());

        $match = $this->onlyJobMatch();
        self::assertSame(self::ESI_JOB_ID, $match->getEsiJobId());
        self::assertSame(5, $match->getRuns());
        self::assertSame(10, $this->step->getRuns());
        self::assertSame(10, $this->step->getQuantity());
    }

    #[Group('issue-3')]
    public function testSecondManualLinkBringingMatchedRunsAboveStepRunsKeepsStepRuns(): void
    {
        $firstLinkedMatch = (new IndustryStepJobMatch())->setEsiJobId(600_000_002)->setRuns(8);
        $this->step->addJobMatch($firstLinkedMatch);
        $this->givenJob($this->mainCharacter, runs: 5);

        $this->link(self::ESI_JOB_ID);

        self::assertCount(2, $this->step->getJobMatches());
        self::assertSame(13, array_sum(array_map(
            static fn (IndustryStepJobMatch $match): int => $match->getRuns(),
            $this->step->getJobMatches()->toArray(),
        )));
        self::assertSame(10, $this->step->getRuns());
        self::assertSame(10, $this->step->getQuantity());
    }

    #[Group('issue-188')]
    public function testCancelledJobIsRefusedAndNoJobMatchIsCreated(): void
    {
        $this->givenStepS();
        $this->givenSpecJob(self::CANCELLED_JOB_ID, 'cancelled', self::ACTIVITY_MANUFACTURING);

        $this->assertLinkIsRefusedWithoutJobMatch(self::CANCELLED_JOB_ID);
    }

    #[Group('issue-188')]
    public function testRevertedJobIsRefusedAndNoJobMatchIsCreated(): void
    {
        $this->givenStepS();
        $this->givenSpecJob(self::REVERTED_JOB_ID, 'reverted', self::ACTIVITY_MANUFACTURING);

        $this->assertLinkIsRefusedWithoutJobMatch(self::REVERTED_JOB_ID);
    }

    #[Group('issue-188')]
    public function testInventionJobOnManufacturingStepOfSameBlueprintIsRefused(): void
    {
        $this->givenStepS();
        $this->givenSpecJob(self::ACTIVE_JOB_ID, 'active', self::ACTIVITY_INVENTION);

        $this->assertLinkIsRefusedWithoutJobMatch(self::ACTIVE_JOB_ID);
    }

    #[Group('issue-188')]
    public function testResearchTimeJobOnManufacturingStepOfSameBlueprintIsRefused(): void
    {
        $this->givenStepS();
        $this->givenSpecJob(self::ACTIVE_JOB_ID, 'active', self::ACTIVITY_RESEARCH_TIME);

        $this->assertLinkIsRefusedWithoutJobMatch(self::ACTIVE_JOB_ID);
    }

    public static function reactionJobActivities(): iterable
    {
        yield 'ESI reaction activity' => [303, self::ACTIVITY_REACTION_ESI];
        yield 'SDE reaction activity' => [304, self::ACTIVITY_REACTION_SDE];
    }

    #[Group('issue-188')]
    #[DataProvider('reactionJobActivities')]
    public function testReactionJobIsLinkedToReactionStep(int $esiJobId, int $activityId): void
    {
        $this->givenStepS('reaction', self::REACTION_FORMULA_TYPE_ID);
        $this->givenSpecJob($esiJobId, 'active', $activityId, self::REACTION_FORMULA_TYPE_ID);

        $this->link($esiJobId);

        self::assertSame($esiJobId, $this->onlyJobMatch()->getEsiJobId());
    }

    public static function copyAndInventionJobs(): iterable
    {
        yield 'copy job on copy step' => ['copy', 306, self::ACTIVITY_COPY];
        yield 'invention job on invention step' => ['invention', 307, self::ACTIVITY_INVENTION];
    }

    #[Group('issue-188')]
    #[DataProvider('copyAndInventionJobs')]
    public function testJobOfStepActivityIsLinked(string $stepActivityType, int $esiJobId, int $activityId): void
    {
        $this->givenStepS($stepActivityType);
        $this->givenSpecJob($esiJobId, 'active', $activityId);

        $this->link($esiJobId);

        self::assertSame($esiJobId, $this->onlyJobMatch()->getEsiJobId());
    }

    #[Group('issue-188')]
    public function testDeliveredManufacturingJobIsLinkedWithItsStatusCostRunsAndEndDate(): void
    {
        $this->givenStepS();
        $this->givenSpecJob(self::DELIVERED_JOB_ID, 'delivered', self::ACTIVITY_MANUFACTURING);
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $this->link(self::DELIVERED_JOB_ID);

        $match = $this->onlyJobMatch();
        self::assertSame(self::DELIVERED_JOB_ID, $match->getEsiJobId());
        self::assertSame('delivered', $match->getStatus());
        self::assertSame(1_250_000.75, $match->getCost());
        self::assertSame(10, $match->getRuns());
        self::assertEquals(new \DateTimeImmutable('2026-10-21 17:30:00'), $match->getEndDate());
        self::assertSame('Main Pilot', $match->getCharacterName());
    }

    #[Group('issue-188')]
    public function testDeliveredJobInstalledInConfiguredAzbelCorrectsStepStructureRecordsPlannedRaitaruAndRecalculatesQuantities(): void
    {
        $this->givenStepS();
        $azbel = $this->createStructureConfig('Azbel', self::AZBEL_LOCATION_ID);
        $this->structureConfigRepository->method('findByUserAndLocationId')
            ->willReturnCallback(fn (User $owner, int $locationId) => $owner === $this->user && $locationId === self::AZBEL_LOCATION_ID ? $azbel : null);
        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn(['materialBonus' => ['total' => self::RAITARU_MATERIAL_BONUS]]);
        $this->calculationService->method('resolveFacilityName')
            ->willReturnCallback(static fn (int $locationId) => $locationId === self::AZBEL_LOCATION_ID ? self::AZBEL_FACILITY_NAME : null);
        $this->givenSpecJob(self::DELIVERED_JOB_ID, 'delivered', self::ACTIVITY_MANUFACTURING, stationId: self::AZBEL_LOCATION_ID);
        $stepCalculator = $this->createMock(IndustryStepCalculator::class);
        $stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($this->project);

        $this->createProcessor(stepCalculator: $stepCalculator)
            ->process($this->input(self::DELIVERED_JOB_ID), new Post(), $this->uriVariables());

        $match = $this->onlyJobMatch();
        self::assertSame($azbel, $this->step->getStructureConfig());
        self::assertSame(self::AZBEL_LOCATION_ID, $match->getFacilityId());
        self::assertSame(self::AZBEL_FACILITY_NAME, $match->getFacilityName());
        self::assertSame('Raitaru', $match->getPlannedStructureName());
        self::assertSame(2.1, $match->getPlannedMaterialBonus());
        self::assertSame('delivered', $match->getStatus());
        self::assertSame(1_250_000.75, $match->getCost());
        self::assertSame(10, $match->getRuns());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function givenJob(
        Character $installer,
        int $blueprintTypeId = self::BLUEPRINT_TYPE_ID,
        int $runs = self::STEP_RUNS,
    ): void {
        $job = (new CachedIndustryJob())
            ->setCharacter($installer)
            ->setJobId(self::ESI_JOB_ID)
            ->setActivityId(1)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($blueprintTypeId + 1)
            ->setRuns($runs)
            ->setCost(self::JOB_COST)
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable('2026-10-01 12:00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-10-02 12:00:00'));

        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(static fn (int $jobId) => $jobId === self::ESI_JOB_ID ? $job : null);
    }

    private function createCharacter(?User $user, string $name): Character
    {
        $character = (new Character())->setName($name);
        $user?->addCharacter($character);

        return $character;
    }

    private function createProcessor(
        ?Security $security = null,
        ?IndustryProjectRepository $projectRepository = null,
        ?IndustryProjectStepRepository $stepRepository = null,
        ?IndustryStepCalculator $stepCalculator = null,
    ): LinkJobProcessor {
        $stepCalculator ??= $this->createStub(IndustryStepCalculator::class);

        return new LinkJobProcessor(
            $security ?? $this->security,
            $projectRepository ?? $this->projectRepository,
            $stepRepository ?? $this->stepRepository,
            $this->jobRepository,
            $this->structureConfigRepository,
            $this->calculationService,
            $stepCalculator,
            $this->mapper,
            $this->entityManager,
            new IndustryJobMatcher(
                $stepCalculator,
                $this->calculationService,
                $this->jobRepository,
                $this->structureConfigRepository,
                $this->entityManager,
            ),
        );
    }

    private function givenSpecJob(
        int $jobId,
        string $status,
        int $activityId,
        int $blueprintTypeId = self::MANUFACTURING_BLUEPRINT_TYPE_ID,
        ?int $stationId = null,
    ): void {
        $job = (new CachedIndustryJob())
            ->setCharacter($this->mainCharacter)
            ->setJobId($jobId)
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($blueprintTypeId + 500)
            ->setRuns(self::SPEC_JOB_RUNS)
            ->setCost(self::SPEC_JOB_COST)
            ->setStatus($status)
            ->setStationId($stationId)
            ->setStartDate(new \DateTimeImmutable('2026-10-20 09:00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-10-21 17:30:00'));

        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(static fn (int $requestedJobId) => $requestedJobId === $jobId ? $job : null);
    }

    private function givenStepS(string $activityType = 'manufacturing', int $blueprintTypeId = self::MANUFACTURING_BLUEPRINT_TYPE_ID): void
    {
        $this->step
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setStructureConfig($this->createStructureConfig('Raitaru', self::RAITARU_LOCATION_ID));
    }

    private function createStructureConfig(string $name, int $locationId): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setUser($this->user)
            ->setName($name)
            ->setLocationId($locationId);
    }

    private function assertLinkIsRefusedWithoutJobMatch(int $esiJobId): void
    {
        $flushCount = 0;
        $this->entityManager->method('flush')->willReturnCallback(static function () use (&$flushCount): void {
            ++$flushCount;
        });

        $error = $this->captureLinkError($esiJobId);

        self::assertInstanceOf(BadRequestHttpException::class, $error);
        self::assertSame(400, $error->getStatusCode());
        self::assertCount(0, $this->step->getJobMatches());
        self::assertSame(0, $flushCount);
    }

    private function link(int $esiJobId): ProjectStepResource
    {
        return $this->processor->process($this->input($esiJobId), new Post(), $this->uriVariables());
    }

    private function captureLinkError(int $esiJobId): HttpExceptionInterface&\Throwable
    {
        try {
            $this->link($esiJobId);
        } catch (HttpExceptionInterface $error) {
            return $error;
        }

        self::fail("Linking ESI job {$esiJobId} should have been refused");
    }

    private function onlyJobMatch(): IndustryStepJobMatch
    {
        self::assertCount(1, $this->step->getJobMatches());

        return $this->step->getJobMatches()->first();
    }

    private function input(int $esiJobId): LinkJobInput
    {
        $input = new LinkJobInput();
        $input->esiJobId = $esiJobId;

        return $input;
    }

    /** @return array{id: string, stepId: string} */
    private function uriVariables(): array
    {
        return ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()];
    }
}
