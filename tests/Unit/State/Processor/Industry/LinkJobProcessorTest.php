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
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Processor\Industry\LinkJobProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
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

    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectStepRepository&Stub $stepRepository;
    private CachedIndustryJobRepository&Stub $jobRepository;
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
        $this->mapper = $this->createStub(IndustryResourceMapper::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->processor = new LinkJobProcessor(
            $this->security,
            $this->projectRepository,
            $this->stepRepository,
            $this->jobRepository,
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(IndustryStepCalculator::class),
            $this->mapper,
            $this->entityManager,
        );

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
        $processor = new LinkJobProcessor(
            $security,
            $this->projectRepository,
            $this->stepRepository,
            $this->jobRepository,
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(IndustryStepCalculator::class),
            $this->mapper,
            $this->entityManager,
        );

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
        $processor = new LinkJobProcessor(
            $this->security,
            $projectRepository,
            $this->stepRepository,
            $this->jobRepository,
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(IndustryStepCalculator::class),
            $this->mapper,
            $this->entityManager,
        );

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
        $processor = new LinkJobProcessor(
            $this->security,
            $this->projectRepository,
            $stepRepository,
            $this->jobRepository,
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(IndustryStepCalculator::class),
            $this->mapper,
            $this->entityManager,
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $processor->process($this->input(self::ESI_JOB_ID), new Post(), $this->uriVariables());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function givenJob(Character $installer, int $blueprintTypeId = self::BLUEPRINT_TYPE_ID): void
    {
        $job = (new CachedIndustryJob())
            ->setCharacter($installer)
            ->setJobId(self::ESI_JOB_ID)
            ->setActivityId(1)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($blueprintTypeId + 1)
            ->setRuns(self::STEP_RUNS)
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
