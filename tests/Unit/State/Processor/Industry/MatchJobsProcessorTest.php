<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\MatchJobsResultResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Sync\CharacterSyncFailure;
use App\Service\Sync\IndustryJobSyncService;
use App\State\Processor\Industry\MatchJobsProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(MatchJobsProcessor::class)]
#[CoversClass(CharacterSyncFailure::class)]
#[Group('issue-197')]
final class MatchJobsProcessorTest extends TestCase
{
    private const string PROJECT_ID = '0196f6a2-7c1e-7d3a-9b40-197000000001';
    private const string PILOT_ONE = 'Pilot One';
    private const string PILOT_TWO = 'Pilot Two';
    private const string PILOT_THREE = 'Pilot Three';
    private const int PILOT_ONE_ID = 2_112_000_001;
    private const int PILOT_TWO_ID = 2_112_000_002;
    private const int PILOT_THREE_ID = 2_112_000_003;

    private User $user;
    private Character $pilotTwo;
    private Character $pilotThree;
    private IndustryProject $project;
    private BufferingLogger $logger;
    private ?User $authenticatedUser;
    private ?IndustryProject $foundProject;
    private ProjectStepResource $stepResource;

    private array $mappedSteps = [];

    private array $syncFailuresByCharacterName = [];

    private array $syncedCharacterNames = [];

    private int $matchEsiJobsCalls = 0;

    private string $projectIdInUrl = self::PROJECT_ID;

    private int $projectLookups = 0;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->addCharacterWithToken(self::PILOT_ONE, self::PILOT_ONE_ID);
        $this->pilotTwo = $this->addCharacterWithToken(self::PILOT_TWO, self::PILOT_TWO_ID);
        $this->pilotThree = $this->addCharacterWithToken(self::PILOT_THREE, self::PILOT_THREE_ID);
        $this->project = (new IndustryProject())->setUser($this->user);
        $this->logger = new BufferingLogger();
        $this->authenticatedUser = $this->user;
        $this->foundProject = $this->project;
        $this->stepResource = new ProjectStepResource();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public static function provideSingleCharacterSyncFailure(): iterable
    {
        yield 'E1 forbidden' => [
            new EsiApiException('Forbidden for Pilot Two', 403),
            ['characterName' => self::PILOT_TWO, 'reason' => 'esi_error', 'statusCode' => 403],
        ];
        yield 'E2 maintenance' => [
            new EsiApiException('Service unavailable for Pilot Two', 503),
            ['characterName' => self::PILOT_TWO, 'reason' => 'esi_unavailable', 'statusCode' => 503],
        ];
        yield 'E3 error limit' => [
            new EsiApiException('Error limited for Pilot Two', 420),
            ['characterName' => self::PILOT_TWO, 'reason' => 'esi_rate_limited', 'statusCode' => 420],
        ];
        yield 'E4 rate limit' => [
            new EsiApiException('Too many requests for Pilot Two', 429),
            ['characterName' => self::PILOT_TWO, 'reason' => 'esi_rate_limited', 'statusCode' => 429],
        ];
        yield 'E5 server error' => [
            new EsiApiException('Internal server error for Pilot Two', 500),
            ['characterName' => self::PILOT_TWO, 'reason' => 'esi_unavailable', 'statusCode' => 500],
        ];
        yield 'E6 network error' => [
            new EsiApiException('Network error for Pilot Two', 0),
            ['characterName' => self::PILOT_TWO, 'reason' => 'esi_unavailable', 'statusCode' => 0],
        ];
        yield 'E7 revoked authorization' => [
            new EveAuthRequiredException((string) self::PILOT_TWO_ID),
            ['characterName' => self::PILOT_TWO, 'reason' => 'authentication_required', 'statusCode' => null],
        ];
        yield 'E8 unexpected value' => [
            new \UnexpectedValueException('ESI returned no corporation for Pilot Two'),
            ['characterName' => self::PILOT_TWO, 'reason' => 'sync_failed', 'statusCode' => null],
        ];
    }

    #[DataProvider('provideSingleCharacterSyncFailure')]
    public function testNamesTheCharacterWhoseJobSyncFailedWithItsReason(\Throwable $failure, array $expectedFailure): void
    {
        $this->syncFailuresByCharacterName[self::PILOT_TWO] = $failure;

        $result = $this->matchJobs();

        $this->assertSame(2, $result->syncedCharacters);
        $this->assertSame([$expectedFailure], $result->failedCharacters);
        $this->assertSame(1, $this->matchEsiJobsCalls);
    }

    public function testLogsAnEsiMaintenanceAsAWarningWithTheStatusCode(): void
    {
        $this->syncFailuresByCharacterName[self::PILOT_TWO] = new EsiApiException('Service unavailable for Pilot Two', 503);

        $this->matchJobs();

        $this->assertSame(
            [['warning', ['characterName' => self::PILOT_TWO, 'error' => 'Service unavailable for Pilot Two', 'statusCode' => 503]]],
            $this->loggedLevelsAndContexts(),
        );
    }

    public function testLogsAFailureOtherThanEsiAsAWarningWithoutStatusCode(): void
    {
        $this->syncFailuresByCharacterName[self::PILOT_THREE] = new EveAuthRequiredException((string) self::PILOT_THREE_ID, 'Grant revoked for Pilot Three');

        $this->matchJobs();

        $this->assertSame(
            [['warning', ['characterName' => self::PILOT_THREE, 'error' => 'Grant revoked for Pilot Three']]],
            $this->loggedLevelsAndContexts(),
        );
    }

    public function testDoesNotSyncNorCountNorLogACharacterWithoutToken(): void
    {
        $this->pilotTwo->setEveToken(null);

        $result = $this->matchJobs();

        $this->assertSame(2, $result->syncedCharacters);
        $this->assertSame(
            [['characterName' => self::PILOT_TWO, 'reason' => 'missing_token', 'statusCode' => null]],
            $result->failedCharacters,
        );
        $this->assertSame([self::PILOT_ONE, self::PILOT_THREE], $this->syncedCharacterNames);
        $this->assertSame([], $this->loggedLevelsAndContexts());
    }

    public function testMatchesTheCachedJobsOnceAndNamesEveryCharacterInOrderWhenAllSyncsFail(): void
    {
        $this->syncFailuresByCharacterName[self::PILOT_ONE] = new EsiApiException('Service unavailable for Pilot One', 503);
        $this->syncFailuresByCharacterName[self::PILOT_TWO] = new EsiApiException('Unauthorized for Pilot Two', 401);
        $this->pilotThree->setEveToken(null);

        $result = $this->matchJobs();

        $this->assertSame(0, $result->syncedCharacters);
        $this->assertSame(
            [
                ['characterName' => self::PILOT_ONE, 'reason' => 'esi_unavailable', 'statusCode' => 503],
                ['characterName' => self::PILOT_TWO, 'reason' => 'esi_error', 'statusCode' => 401],
                ['characterName' => self::PILOT_THREE, 'reason' => 'missing_token', 'statusCode' => null],
            ],
            $result->failedCharacters,
        );
        $this->assertSame(1, $this->matchEsiJobsCalls);
        $this->assertSame(
            [
                ['warning', ['characterName' => self::PILOT_ONE, 'error' => 'Service unavailable for Pilot One', 'statusCode' => 503]],
                ['warning', ['characterName' => self::PILOT_TWO, 'error' => 'Unauthorized for Pilot Two', 'statusCode' => 401]],
            ],
            $this->loggedLevelsAndContexts(),
        );
    }

    public function testNamesFailedCharactersInTheOrderOfTheUserCharacters(): void
    {
        $this->syncFailuresByCharacterName[self::PILOT_THREE] = new EsiApiException('Too many requests for Pilot Three', 429);
        $this->syncFailuresByCharacterName[self::PILOT_ONE] = new \RuntimeException('Database lost for Pilot One');

        $result = $this->matchJobs();

        $this->assertSame(1, $result->syncedCharacters);
        $this->assertSame(
            [
                ['characterName' => self::PILOT_ONE, 'reason' => 'sync_failed', 'statusCode' => null],
                ['characterName' => self::PILOT_THREE, 'reason' => 'esi_rate_limited', 'statusCode' => 429],
            ],
            $result->failedCharacters,
        );
    }

    public function testCountsEveryCharacterAndNamesNoneWhenAllSyncsSucceed(): void
    {
        $result = $this->matchJobs();

        $this->assertSame(3, $result->syncedCharacters);
        $this->assertSame([], $result->failedCharacters);
        $this->assertSame([self::PILOT_ONE, self::PILOT_TWO, self::PILOT_THREE], $this->syncedCharacterNames);
        $this->assertSame(1, $this->matchEsiJobsCalls);
    }

    public function testAnswersWithoutWarningField(): void
    {
        $this->syncFailuresByCharacterName[self::PILOT_TWO] = new EsiApiException('Service unavailable for Pilot Two', 503);

        $result = $this->matchJobs();

        $this->assertSame(
            ['steps', 'jobsCost', 'syncedCharacters', 'failedCharacters'],
            array_keys(get_object_vars($result)),
        );
    }

    public function testRejectsAProjectOfAnotherUserWith404(): void
    {
        $this->foundProject = (new IndustryProject())->setUser(new User());

        $rejection = $this->rejectionOfMatchJobs();

        $this->assertInstanceOf(NotFoundHttpException::class, $rejection);
        $this->assertSame(404, $rejection->getStatusCode());
        $this->assertSame(0, $this->matchEsiJobsCalls);
        $this->assertSame([], $this->syncedCharacterNames);
    }

    public function testRejectsAnUnknownProjectWith404(): void
    {
        $this->foundProject = null;

        $rejection = $this->rejectionOfMatchJobs();

        $this->assertInstanceOf(NotFoundHttpException::class, $rejection);
        $this->assertSame(404, $rejection->getStatusCode());
        $this->assertSame(0, $this->matchEsiJobsCalls);
        $this->assertSame([], $this->syncedCharacterNames);
    }

    #[Group('issue-200')]
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjectsNorSyncing(): void
    {
        $this->projectIdInUrl = 'abc';

        self::assertSame(
            ['404 Project not found', 0, [], 0],
            [$this->outcomeOfMatchJobs(), $this->projectLookups, $this->syncedCharacterNames, $this->matchEsiJobsCalls],
        );
    }

    #[Group('issue-200')]
    public function testUnknownValidProjectUuidIsNotFoundAfterOneProjectLookup(): void
    {
        $this->projectIdInUrl = '0192f0c4-7b1e-7a3c-9d2e-2000000000ff';
        $this->foundProject = null;

        self::assertSame(['404 Project not found', 1], [$this->outcomeOfMatchJobs(), $this->projectLookups]);
    }

    public function testRejectsAnonymousWith401(): void
    {
        $this->authenticatedUser = null;

        $rejection = $this->rejectionOfMatchJobs();

        $this->assertInstanceOf(UnauthorizedHttpException::class, $rejection);
        $this->assertSame(401, $rejection->getStatusCode());
        $this->assertSame(0, $this->matchEsiJobsCalls);
        $this->assertSame([], $this->syncedCharacterNames);
    }

    public function testMapsEachStepAndKeepsJobsCost(): void
    {
        $step = (new IndustryProjectStep())->addJobMatch((new IndustryStepJobMatch())->setCost(1_482_350.25));
        $this->project->addStep($step);

        $result = $this->matchJobs();

        $this->assertSame([$step], $this->mappedSteps);
        $this->assertSame([$this->stepResource], $result->steps);
        $this->assertSame(1_482_350.25, $result->jobsCost);
    }

    private function rejectionOfMatchJobs(): HttpExceptionInterface
    {
        try {
            $this->matchJobs();
        } catch (HttpExceptionInterface $rejection) {
            return $rejection;
        }

        $this->fail('The job match was not rejected');
    }

    private function outcomeOfMatchJobs(): string
    {
        try {
            $this->matchJobs();
        } catch (HttpExceptionInterface $rejection) {
            return $rejection->getStatusCode().' '.$rejection->getMessage();
        } catch (\Throwable $throwable) {
            return $throwable::class.': '.$throwable->getMessage();
        }

        return 'matched';
    }

    private function addCharacterWithToken(string $name, int $eveCharacterId): Character
    {
        $character = (new Character())
            ->setName($name)
            ->setEveCharacterId($eveCharacterId);
        $character->setEveToken(new EveToken());
        $this->user->addCharacter($character);

        return $character;
    }

    private function matchJobs(): MatchJobsResultResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->authenticatedUser);

        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(function (): ?IndustryProject {
            $this->projectLookups++;

            return $this->foundProject;
        });

        $jobSyncService = $this->createStub(IndustryJobSyncService::class);
        $jobSyncService->method('syncCharacterJobs')->willReturnCallback(function (Character $character): void {
            $this->syncedCharacterNames[] = $character->getName();
            if (isset($this->syncFailuresByCharacterName[$character->getName()])) {
                throw $this->syncFailuresByCharacterName[$character->getName()];
            }
        });

        $jobMatcher = $this->createStub(IndustryJobMatcher::class);
        $jobMatcher->method('matchEsiJobs')->willReturnCallback(function (IndustryProject $project): void {
            $this->assertSame($this->project, $project);
            $this->matchEsiJobsCalls++;
        });

        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturnCallback(function (IndustryProjectStep $step): ProjectStepResource {
            $this->mappedSteps[] = $step;

            return $this->stepResource;
        });

        $processor = new MatchJobsProcessor(
            $security,
            $projectRepository,
            $jobMatcher,
            $jobSyncService,
            $mapper,
            $this->logger,
        );

        return $processor->process(null, new Post(), ['id' => $this->projectIdInUrl]);
    }

    private function loggedLevelsAndContexts(): array
    {
        return array_map(
            static fn (array $log): array => [$log[0], $log[2]],
            $this->logger->cleanLogs(),
        );
    }
}
