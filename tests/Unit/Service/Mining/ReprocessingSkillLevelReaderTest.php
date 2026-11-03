<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\ReprocessingFailure;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Mining\ReprocessingSkillLevelReader;
use App\Service\Mining\ReprocessingSkillLevels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReprocessingSkillLevelReader::class)]
#[CoversClass(ReprocessingSkillLevels::class)]
#[Group('issue-269')]
final class ReprocessingSkillLevelReaderTest extends TestCase
{
    private const string SKILLS_SCOPE = 'esi-skills.read_skills.v1';
    private const string MINING_SCOPE = 'esi-industry.read_character_mining.v1';
    private const int PILOT_ID = 2_112_269_001;
    private const string PILOT_SKILLS_ENDPOINT = '/characters/2112269001/skills/';
    private const int REPROCESSING = 3385;
    private const int REPROCESSING_EFFICIENCY = 3389;
    private const int SIMPLE_ORE_PROCESSING = 60377;
    private const int COHERENT_ORE_PROCESSING = 60378;
    private const int ICE_PROCESSING = 18025;
    private const int MINING = 3386;

    private Character $pilot;
    private EveToken $pilotToken;
    private ?array $esiResponse = null;
    private ?\Throwable $esiFailure = null;
    private array $esiCalls = [];
    private array $accessTokenRequests = [];

    protected function setUp(): void
    {
        $this->pilotToken = (new EveToken())->setScopes([self::MINING_SCOPE, self::SKILLS_SCOPE]);
        $this->pilot = (new Character())->setName('Ilyra Castell')->setEveCharacterId(self::PILOT_ID);
        $this->pilot->setEveToken($this->pilotToken);
        (new User())->addCharacter($this->pilot);
    }

    public function testReadsTheActiveLevelsOfReprocessingReprocessingEfficiencyAndEachOreProcessingSkill(): void
    {
        $this->esiResponse = ['skills' => [
            $this->skill(self::MINING, 5),
            $this->skill(self::REPROCESSING, 4),
            $this->skill(self::REPROCESSING_EFFICIENCY, 3),
            $this->skill(self::SIMPLE_ORE_PROCESSING, 5),
            $this->skill(self::COHERENT_ORE_PROCESSING, 2),
        ]];

        $levels = $this->read();

        $this->assertInstanceOf(ReprocessingSkillLevels::class, $levels);
        $this->assertSame(
            [4, 3, 5, 2],
            [$levels->reprocessing, $levels->reprocessingEfficiency, $levels->oreProcessing(self::SIMPLE_ORE_PROCESSING), $levels->oreProcessing(self::COHERENT_ORE_PROCESSING)],
        );
    }

    public function testASkillMissingFromTheEsiListIsAKnownLevelZero(): void
    {
        $this->esiResponse = ['skills' => [
            $this->skill(self::REPROCESSING_EFFICIENCY, 3),
            $this->skill(self::SIMPLE_ORE_PROCESSING, 4),
        ]];

        $levels = $this->read();

        $this->assertInstanceOf(ReprocessingSkillLevels::class, $levels);
        $this->assertSame(
            [0, 3, 4, 0],
            [$levels->reprocessing, $levels->reprocessingEfficiency, $levels->oreProcessing(self::SIMPLE_ORE_PROCESSING), $levels->oreProcessing(self::ICE_PROCESSING)],
        );
    }

    public function testASkillListedAtLevelZeroReadsZero(): void
    {
        $this->esiResponse = ['skills' => [
            $this->skill(self::REPROCESSING, 0),
            $this->skill(self::REPROCESSING_EFFICIENCY, 1),
            $this->skill(self::ICE_PROCESSING, 0),
        ]];

        $levels = $this->read();

        $this->assertInstanceOf(ReprocessingSkillLevels::class, $levels);
        $this->assertSame([0, 1, 0], [$levels->reprocessing, $levels->reprocessingEfficiency, $levels->oreProcessing(self::ICE_PROCESSING)]);
    }

    public function testReadsTheActiveLevelRatherThanTheTrainedLevel(): void
    {
        $this->esiResponse = ['skills' => [
            ['skill_id' => self::REPROCESSING, 'active_skill_level' => 3, 'trained_skill_level' => 5, 'skillpoints_in_skill' => 256000],
            ['skill_id' => self::REPROCESSING_EFFICIENCY, 'active_skill_level' => 2, 'trained_skill_level' => 4, 'skillpoints_in_skill' => 90510],
            ['skill_id' => self::SIMPLE_ORE_PROCESSING, 'active_skill_level' => 1, 'trained_skill_level' => 5, 'skillpoints_in_skill' => 256000],
        ]];

        $levels = $this->read();

        $this->assertInstanceOf(ReprocessingSkillLevels::class, $levels);
        $this->assertSame([3, 2, 1], [$levels->reprocessing, $levels->reprocessingEfficiency, $levels->oreProcessing(self::SIMPLE_ORE_PROCESSING)]);
    }

    public function testCallsTheSkillsEndpointOnceWithTheCharacterTokenRefreshedByTheTokenManager(): void
    {
        $this->esiResponse = ['skills' => [$this->skill(self::REPROCESSING, 4)]];

        $this->read();

        $this->assertSame([$this->pilotToken], $this->accessTokenRequests);
        $this->assertSame([[self::PILOT_SKILLS_ENDPOINT, $this->pilotToken]], $this->esiCalls);
    }

    public function testACharacterWithoutTokenIsAMissingTokenFailureWithoutCallingEsi(): void
    {
        $this->pilot->setEveToken(null);

        $this->assertSame(ReprocessingFailure::MissingToken, $this->read());
        $this->assertSame([], $this->accessTokenRequests);
        $this->assertSame([], $this->esiCalls);
    }

    public function testATokenWithoutTheSkillsScopeIsAMissingScopeFailureWithoutCallingEsi(): void
    {
        $this->pilotToken->setScopes([self::MINING_SCOPE]);

        $this->assertSame(ReprocessingFailure::MissingScope, $this->read());
        $this->assertSame([], $this->accessTokenRequests);
        $this->assertSame([], $this->esiCalls);
    }

    #[DataProvider('esiErrorStatusCodes')]
    public function testAnEsiErrorIsAnEsiErrorFailureAndNeverLevelZero(int $statusCode): void
    {
        $this->esiFailure = EsiApiException::fromResponse($statusCode, 'ESI failed', self::PILOT_SKILLS_ENDPOINT);

        $this->assertSame(ReprocessingFailure::EsiError, $this->read());
        $this->assertSame([[self::PILOT_SKILLS_ENDPOINT, $this->pilotToken]], $this->esiCalls);
    }

    public static function esiErrorStatusCodes(): iterable
    {
        yield 'server error' => [502];
        yield 'error budget exhausted' => [420];
        yield 'rate limited' => [429];
        yield 'network error' => [0];
    }

    #[DataProvider('unreadableSkillsResponses')]
    public function testAnUnreadableSkillsResponseIsAnEsiErrorFailureAndNeverLevelZero(array $response): void
    {
        $this->esiResponse = $response;

        $this->assertSame(ReprocessingFailure::EsiError, $this->read());
    }

    public static function unreadableSkillsResponses(): iterable
    {
        yield 'no skills list' => [['total_sp' => 5_000_000]];
        yield 'skills is not a list' => [['skills' => 'none', 'total_sp' => 5_000_000]];
        yield 'an entry without skill_id' => [['skills' => [
            ['skill_id' => self::REPROCESSING, 'active_skill_level' => 4],
            ['active_skill_level' => 3],
        ]]];
        yield 'an entry without active_skill_level' => [['skills' => [
            ['skill_id' => self::REPROCESSING, 'active_skill_level' => 4],
            ['skill_id' => self::REPROCESSING_EFFICIENCY, 'trained_skill_level' => 3],
        ]]];
        yield 'a level given as a string' => [['skills' => [
            ['skill_id' => self::REPROCESSING, 'active_skill_level' => '4'],
        ]]];
        yield 'a skill id given as a float' => [['skills' => [
            ['skill_id' => 3385.0, 'active_skill_level' => 4],
        ]]];
        yield 'an entry that is not an object' => [['skills' => [4]]];
    }

    private function skill(int $skillId, int $activeLevel): array
    {
        return ['skill_id' => $skillId, 'active_skill_level' => $activeLevel, 'trained_skill_level' => $activeLevel, 'skillpoints_in_skill' => 1000 * ($activeLevel + 1)];
    }

    private function read(): ReprocessingSkillLevels|ReprocessingFailure
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(function (EveToken $token): string {
            $this->accessTokenRequests[] = $token;

            return 'access-token';
        });

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint, ?EveToken $token = null): array {
            $this->esiCalls[] = [$endpoint, $token];
            if ($this->esiFailure !== null) {
                throw $this->esiFailure;
            }

            return $this->esiResponse ?? ['skills' => []];
        });

        return (new ReprocessingSkillLevelReader($esiClient, $tokenManager))->read($this->pilot);
    }
}
