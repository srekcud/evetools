<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\CharacterSkillCollectionResource;
use App\ApiResource\Industry\CharacterSkillResource;
use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\InventionService;
use App\Service\Industry\TimePerRunCalculator;
use App\Service\Sync\CharacterSyncFailure;
use App\State\Processor\Industry\SyncCharacterSkillsProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(SyncCharacterSkillsProcessor::class)]
#[CoversClass(CharacterSyncFailure::class)]
#[Group('issue-197')]
final class SyncCharacterSkillsProcessorTest extends TestCase
{
    private const string SKILLS_SCOPE = 'esi-skills.read_skills.v1';
    private const string JOBS_SCOPE = 'esi-industry.read_character_jobs.v1';
    private const string PILOT_ONE = 'Pilot One';
    private const string PILOT_TWO = 'Pilot Two';
    private const int PILOT_ONE_ID = 2_112_000_001;
    private const int PILOT_TWO_ID = 2_112_000_002;
    private const string PILOT_ONE_SKILLS_ENDPOINT = '/characters/2112000001/skills/';
    private const string PILOT_TWO_SKILLS_ENDPOINT = '/characters/2112000002/skills/';

    private User $user;
    private Character $pilotOne;
    private Character $pilotTwo;
    private EveToken $pilotOneToken;
    private EveToken $pilotTwoToken;
    private BufferingLogger $logger;

    private array $accessTokenRequests = [];

    private array $skillsEndpointCalls = [];

    private array $accessTokenFailures = [];

    private array $skillsEndpointFailures = [];

    private array $cachedSkillsByCharacterName = [];

    private array $esiSkillsByEndpoint = [];

    private array $persistedSkillsByCharacterName = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $this->pilotOneToken = (new EveToken())->setScopes([self::SKILLS_SCOPE]);
        $this->pilotTwoToken = (new EveToken())->setScopes([self::SKILLS_SCOPE, self::JOBS_SCOPE]);
        $this->pilotOne = $this->addCharacter(self::PILOT_ONE, self::PILOT_ONE_ID, $this->pilotOneToken);
        $this->pilotTwo = $this->addCharacter(self::PILOT_TWO, self::PILOT_TWO_ID, $this->pilotTwoToken);
        $this->logger = new BufferingLogger();
        $this->cachedSkillsByCharacterName = [self::PILOT_ONE => [], self::PILOT_TWO => []];
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testDoesNotCallEsiNorCountACharacterWhoseTokenLacksTheSkillsScope(): void
    {
        $this->pilotTwoToken->setScopes([self::JOBS_SCOPE]);

        $result = $this->syncSkills();

        $this->assertSame(1, $result->syncedCount);
        $this->assertSame(
            [['characterName' => self::PILOT_TWO, 'reason' => 'missing_scope', 'statusCode' => null]],
            $result->failedCharacters,
        );
        $this->assertSame([self::PILOT_ONE], $this->accessTokenRequests);
        $this->assertSame([self::PILOT_ONE_SKILLS_ENDPOINT], $this->skillsEndpointCalls);
        $this->assertSame([self::PILOT_ONE, self::PILOT_TWO], $this->characterNames($result));
        $this->assertSame([], $this->loggedLevelsAndContexts());
    }

    public function testDoesNotCallEsiNorCountACharacterWithoutToken(): void
    {
        $this->pilotTwo->setEveToken(null);

        $result = $this->syncSkills();

        $this->assertSame(1, $result->syncedCount);
        $this->assertSame(
            [['characterName' => self::PILOT_TWO, 'reason' => 'missing_token', 'statusCode' => null]],
            $result->failedCharacters,
        );
        $this->assertSame([self::PILOT_ONE_SKILLS_ENDPOINT], $this->skillsEndpointCalls);
        $this->assertSame([self::PILOT_ONE, self::PILOT_TWO], $this->characterNames($result));
        $this->assertSame([], $this->loggedLevelsAndContexts());
    }

    public function testNamesTheCharacterWhoseAuthorizationIsRevoked(): void
    {
        $this->accessTokenFailures[self::PILOT_TWO] = new EveAuthRequiredException((string) self::PILOT_TWO_ID, 'Grant revoked for Pilot Two');

        $result = $this->syncSkills();

        $this->assertSame(1, $result->syncedCount);
        $this->assertSame(
            [['characterName' => self::PILOT_TWO, 'reason' => 'authentication_required', 'statusCode' => null]],
            $result->failedCharacters,
        );
        $this->assertSame([self::PILOT_ONE_SKILLS_ENDPOINT], $this->skillsEndpointCalls);
    }

    public function testNamesTheCharacterWhoseSkillsAreForbidden(): void
    {
        $this->skillsEndpointFailures[self::PILOT_TWO_SKILLS_ENDPOINT] = new EsiApiException('Forbidden for Pilot Two', 403);

        $result = $this->syncSkills();

        $this->assertSame(1, $result->syncedCount);
        $this->assertSame(
            [['characterName' => self::PILOT_TWO, 'reason' => 'esi_error', 'statusCode' => 403]],
            $result->failedCharacters,
        );
    }

    public function testNamesAndLogsTheCharacterWhoseSkillsHitAnEsiBadGateway(): void
    {
        $this->skillsEndpointFailures[self::PILOT_TWO_SKILLS_ENDPOINT] = new EsiApiException('Bad gateway for Pilot Two', 502);

        $result = $this->syncSkills();

        $this->assertSame(1, $result->syncedCount);
        $this->assertSame(
            [['characterName' => self::PILOT_TWO, 'reason' => 'esi_unavailable', 'statusCode' => 502]],
            $result->failedCharacters,
        );
        $this->assertSame([self::PILOT_ONE, self::PILOT_TWO], $this->characterNames($result));
        $this->assertSame(
            [['warning', ['characterName' => self::PILOT_TWO, 'error' => 'Bad gateway for Pilot Two', 'statusCode' => 502]]],
            $this->loggedLevelsAndContexts(),
        );
    }

    public function testNamesEveryFailedCharacterInTheOrderOfTheUserCharacters(): void
    {
        $this->skillsEndpointFailures[self::PILOT_ONE_SKILLS_ENDPOINT] = new EsiApiException('Error limited for Pilot One', 420);
        $this->pilotTwo->setEveToken(null);

        $result = $this->syncSkills();

        $this->assertSame(0, $result->syncedCount);
        $this->assertSame(
            [
                ['characterName' => self::PILOT_ONE, 'reason' => 'esi_rate_limited', 'statusCode' => 420],
                ['characterName' => self::PILOT_TWO, 'reason' => 'missing_token', 'statusCode' => null],
            ],
            $result->failedCharacters,
        );
        $this->assertSame([self::PILOT_ONE, self::PILOT_TWO], $this->characterNames($result));
        $this->assertSame(
            [['warning', ['characterName' => self::PILOT_ONE, 'error' => 'Error limited for Pilot One', 'statusCode' => 420]]],
            $this->loggedLevelsAndContexts(),
        );
    }

    public function testCountsEveryCharacterAndNamesNoneWhenAllSyncsSucceed(): void
    {
        $result = $this->syncSkills();

        $this->assertSame(2, $result->syncedCount);
        $this->assertSame([], $result->failedCharacters);
        $this->assertSame([self::PILOT_ONE_SKILLS_ENDPOINT, self::PILOT_TWO_SKILLS_ENDPOINT], $this->skillsEndpointCalls);
    }

    public function testAnswersWithoutWarningField(): void
    {
        $this->skillsEndpointFailures[self::PILOT_TWO_SKILLS_ENDPOINT] = new EsiApiException('Bad gateway for Pilot Two', 502);

        $result = $this->syncSkills();

        $this->assertSame(
            ['characters', 'syncedCount', 'failedCharacters'],
            array_keys(get_object_vars($result)),
        );
    }

    public function testReturnsEachCharacterWithItsOwnCachedSkills(): void
    {
        $this->pilotTwoToken->setScopes([self::JOBS_SCOPE]);
        $this->cachedSkillsByCharacterName[self::PILOT_ONE] = [
            $this->cachedSkill($this->pilotOne, CachedCharacterSkill::SKILL_INDUSTRY, 5, true, '2026-10-20T08:15:00+00:00'),
            $this->cachedSkill($this->pilotOne, CachedCharacterSkill::SKILL_REACTIONS, 2, true, '2026-10-21T11:30:00+00:00'),
        ];
        $this->cachedSkillsByCharacterName[self::PILOT_TWO] = [
            $this->cachedSkill($this->pilotTwo, CachedCharacterSkill::SKILL_INDUSTRY, 3, false, '2026-10-27T19:42:00+00:00'),
            $this->cachedSkill($this->pilotTwo, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY, 4, false, '2026-10-26T06:05:00+00:00'),
        ];

        $result = $this->syncSkills();

        $this->assertSame(
            [
                [self::PILOT_ONE, 5, 0, 2, 'manual', '2026-10-21T11:30:00+00:00'],
                [self::PILOT_TWO, 3, 4, 0, 'esi', '2026-10-27T19:42:00+00:00'],
            ],
            array_map(
                static fn (CharacterSkillResource $character): array => [
                    $character->characterName,
                    $character->industry,
                    $character->advancedIndustry,
                    $character->reactions,
                    $character->source,
                    $character->lastSyncAt,
                ],
                $result->characters,
            ),
        );
    }

    #[Group('issue-275')]
    public function testWritesTheScienceLevelReadFromEsi(): void
    {
        $this->esiSkillsByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = [
            $this->esiSkill(CachedCharacterSkill::SKILL_SCIENCE, 4, 5),
        ];

        $this->syncSkills();

        $this->assertSame([[CachedCharacterSkill::SKILL_SCIENCE, 4, false]], $this->persistedSkillsOf(self::PILOT_ONE));
    }

    #[Group('issue-275')]
    public function testWritesScienceAtLevelZeroAndNoOtherSkillWhenEsiReturnsNoSkill(): void
    {
        $this->syncSkills();

        $this->assertSame([[CachedCharacterSkill::SKILL_SCIENCE, 0, false]], $this->persistedSkillsOf(self::PILOT_ONE));
    }

    #[Group('issue-275')]
    public function testWritesScienceAtLevelZeroForACharacterSyncedBeforeWithoutScience(): void
    {
        $industry = $this->cachedSkill($this->pilotOne, CachedCharacterSkill::SKILL_INDUSTRY, 5, false, '2026-10-12T09:00:00+00:00');
        $this->cachedSkillsByCharacterName[self::PILOT_ONE] = [$industry];
        $this->esiSkillsByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = [
            $this->esiSkill(CachedCharacterSkill::SKILL_INDUSTRY, 5, 5),
        ];

        $this->syncSkills();

        $this->assertSame(
            [[CachedCharacterSkill::SKILL_SCIENCE, 0, false], 5],
            [...$this->persistedSkillsOf(self::PILOT_ONE), $industry->getLevel()],
        );
    }

    #[Group('issue-275')]
    public function testUpdatesTheScienceLevelOfAnExistingEsiLine(): void
    {
        $science = $this->cachedSkill($this->pilotOne, CachedCharacterSkill::SKILL_SCIENCE, 3, false, '2026-10-14T16:20:00+00:00');
        $this->cachedSkillsByCharacterName[self::PILOT_ONE] = [$science];
        $this->esiSkillsByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = [
            $this->esiSkill(CachedCharacterSkill::SKILL_SCIENCE, 4, 4),
        ];

        $this->syncSkills();

        $this->assertSame([4, []], [$science->getLevel(), $this->persistedSkillsOf(self::PILOT_ONE)]);
    }

    #[Group('issue-275')]
    public function testSetsTheScienceLevelOfAnExistingEsiLineToZeroWhenEsiNoLongerReturnsScience(): void
    {
        $science = $this->cachedSkill($this->pilotOne, CachedCharacterSkill::SKILL_SCIENCE, 2, false, '2026-10-15T07:45:00+00:00');
        $this->cachedSkillsByCharacterName[self::PILOT_ONE] = [$science];

        $this->syncSkills();

        $this->assertSame([0, []], [$science->getLevel(), $this->persistedSkillsOf(self::PILOT_ONE)]);
    }

    #[Group('issue-275')]
    public function testWritesNoScienceLevelForACharacterWhoseTokenLacksTheSkillsScope(): void
    {
        $this->pilotTwoToken->setScopes([self::JOBS_SCOPE]);

        $this->syncSkills();

        $this->assertSame([[], [self::PILOT_ONE_SKILLS_ENDPOINT]], [$this->persistedSkillsOf(self::PILOT_TWO), $this->skillsEndpointCalls]);
    }

    #[Group('issue-275')]
    public function testWritesNoScienceLevelForACharacterWhoseSkillsHitAnEsiBadGateway(): void
    {
        $this->skillsEndpointFailures[self::PILOT_TWO_SKILLS_ENDPOINT] = new EsiApiException('Bad gateway for Pilot Two', 502);

        $result = $this->syncSkills();

        $this->assertSame(
            [[], [['characterName' => self::PILOT_TWO, 'reason' => 'esi_unavailable', 'statusCode' => 502]]],
            [$this->persistedSkillsOf(self::PILOT_TWO), $result->failedCharacters],
        );
    }

    private function esiSkill(int $skillId, int $activeSkillLevel, int $trainedSkillLevel): array
    {
        return [
            'skill_id' => $skillId,
            'active_skill_level' => $activeSkillLevel,
            'trained_skill_level' => $trainedSkillLevel,
            'skillpoints_in_skill' => 256_000,
        ];
    }

    private function persistedSkillsOf(string $characterName): array
    {
        return $this->persistedSkillsByCharacterName[$characterName] ?? [];
    }

    private function cachedSkill(Character $character, int $skillId, int $level, bool $isManual, string $cachedAt): CachedCharacterSkill
    {
        return (new CachedCharacterSkill())
            ->setCharacter($character)
            ->setSkillId($skillId)
            ->setLevel($level)
            ->setIsManual($isManual)
            ->setCachedAt(new \DateTimeImmutable($cachedAt));
    }

    private function addCharacter(string $name, int $eveCharacterId, EveToken $token): Character
    {
        $character = (new Character())
            ->setName($name)
            ->setEveCharacterId($eveCharacterId);
        $character->setEveToken($token);
        $this->user->addCharacter($character);

        return $character;
    }

    private function syncSkills(): CharacterSkillCollectionResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(function (EveToken $token): string {
            $characterName = $token->getCharacter()?->getName() ?? '';
            $this->accessTokenRequests[] = $characterName;
            if (isset($this->accessTokenFailures[$characterName])) {
                throw $this->accessTokenFailures[$characterName];
            }

            return 'access-token-' . $characterName;
        });

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->skillsEndpointCalls[] = $endpoint;
            if (isset($this->skillsEndpointFailures[$endpoint])) {
                throw $this->skillsEndpointFailures[$endpoint];
            }

            return ['skills' => $this->esiSkillsByEndpoint[$endpoint] ?? []];
        });

        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->cachedSkillsByCharacterName[$criteria['character']->getName()],
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedSkillsByCharacterName[$entity->getCharacter()->getName()][] = [$entity->getSkillId(), $entity->getLevel(), $entity->isManual()];
        });

        $mapper = new IndustryResourceMapper(
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(TimePerRunCalculator::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );

        $processor = new SyncCharacterSkillsProcessor(
            $security,
            $esiClient,
            $tokenManager,
            $skillRepository,
            $mapper,
            $entityManager,
            $this->logger,
        );

        return $processor->process(null, new Post());
    }

    private function characterNames(CharacterSkillCollectionResource $result): array
    {
        return array_map(
            static fn (CharacterSkillResource $character): string => $character->characterName,
            $result->characters,
        );
    }

    private function loggedLevelsAndContexts(): array
    {
        return array_map(
            static fn (array $log): array => [$log[0], $log[2]],
            $this->logger->cleanLogs(),
        );
    }
}
