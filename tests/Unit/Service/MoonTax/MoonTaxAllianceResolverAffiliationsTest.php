<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\MoonTax\MoonTaxAffiliation;
use App\Service\MoonTax\MoonTaxAllianceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MoonTaxAllianceResolver::class)]
#[Group('issue-369')]
final class MoonTaxAllianceResolverAffiliationsTest extends TestCase
{
    private const int HOLLOW_CORPORATION = 98_000_011;
    private const int REACH_CORPORATION = 98_000_012;
    private const int LONER_CORPORATION = 98_000_013;
    private const int SABLE_ALLIANCE = 99_000_021;
    private const string HOLLOW_NAME = 'Hollow Rock Mining';
    private const string REACH_NAME = 'Reach Haulers';
    private const string LONER_NAME = 'Solo Prospectors';
    private const string SABLE_NAME = 'Sable Reach Alliance';

    private array $esiAnswers = [];

    private array $esiCalls = [];

    protected function setUp(): void
    {
        $this->esiAnswers = [];
        $this->esiCalls = [];
    }

    public function testAffiliationReadsTheCorporationNameAndItsAllianceWithTheAllianceName(): void
    {
        $this->esiAnswers['/corporations/' . self::HOLLOW_CORPORATION . '/'] = ['name' => self::HOLLOW_NAME, 'alliance_id' => self::SABLE_ALLIANCE];
        $this->esiAnswers['/alliances/' . self::SABLE_ALLIANCE . '/'] = ['name' => self::SABLE_NAME];

        $affiliations = $this->resolver()->affiliations([self::HOLLOW_CORPORATION]);

        self::assertEquals([self::HOLLOW_CORPORATION => new MoonTaxAffiliation(true, self::HOLLOW_NAME, self::SABLE_ALLIANCE, self::SABLE_NAME)], $affiliations);
    }

    public function testCorporationWithoutAllianceIdIsKnownWithoutAlliance(): void
    {
        $this->esiAnswers['/corporations/' . self::LONER_CORPORATION . '/'] = ['name' => self::LONER_NAME];

        $affiliations = $this->resolver()->affiliations([self::LONER_CORPORATION]);

        self::assertEquals([self::LONER_CORPORATION => new MoonTaxAffiliation(true, self::LONER_NAME, null, null)], $affiliations);
    }

    public function testCorporationUnreadableOnEsiStaysUnknownWhileTheOthersAreRead(): void
    {
        $this->esiAnswers['/corporations/' . self::HOLLOW_CORPORATION . '/'] = EsiApiException::fromResponse(500, 'ESI 500', '/corporations/' . self::HOLLOW_CORPORATION . '/');
        $this->esiAnswers['/corporations/' . self::LONER_CORPORATION . '/'] = ['name' => self::LONER_NAME];

        $affiliations = $this->resolver()->affiliations([self::HOLLOW_CORPORATION, self::LONER_CORPORATION]);

        self::assertEquals(
            [
                self::HOLLOW_CORPORATION => MoonTaxAffiliation::unknown(),
                self::LONER_CORPORATION => new MoonTaxAffiliation(true, self::LONER_NAME, null, null),
            ],
            $affiliations,
        );
    }

    public function testAllianceWithoutReadableNameMakesTheAffiliationUnknown(): void
    {
        $this->esiAnswers['/corporations/' . self::HOLLOW_CORPORATION . '/'] = ['name' => self::HOLLOW_NAME, 'alliance_id' => self::SABLE_ALLIANCE];
        $this->esiAnswers['/alliances/' . self::SABLE_ALLIANCE . '/'] = EsiApiException::notFound('No alliance', '/alliances/' . self::SABLE_ALLIANCE . '/');

        $affiliations = $this->resolver()->affiliations([self::HOLLOW_CORPORATION]);

        self::assertEquals([self::HOLLOW_CORPORATION => MoonTaxAffiliation::unknown()], $affiliations);
    }

    #[DataProvider('malformedEsiAnswers')]
    public function testMalformedEsiAnswerMakesTheAffiliationUnknownNeverADefault(array $corporationAnswer, ?array $allianceAnswer): void
    {
        $this->esiAnswers['/corporations/' . self::HOLLOW_CORPORATION . '/'] = $corporationAnswer;
        if ($allianceAnswer !== null) {
            $this->esiAnswers['/alliances/' . self::SABLE_ALLIANCE . '/'] = $allianceAnswer;
        }

        $affiliations = $this->resolver()->affiliations([self::HOLLOW_CORPORATION]);

        self::assertEquals([self::HOLLOW_CORPORATION => MoonTaxAffiliation::unknown()], $affiliations);
    }

    public static function malformedEsiAnswers(): iterable
    {
        yield 'corporation without name' => [['alliance_id' => self::SABLE_ALLIANCE], ['name' => self::SABLE_NAME]];
        yield 'corporation name is an integer' => [['name' => 4_242, 'alliance_id' => self::SABLE_ALLIANCE], ['name' => self::SABLE_NAME]];
        yield 'corporation name is null' => [['name' => null, 'alliance_id' => self::SABLE_ALLIANCE], ['name' => self::SABLE_NAME]];
        yield 'alliance id is a string' => [['name' => self::HOLLOW_NAME, 'alliance_id' => '99000021'], ['name' => self::SABLE_NAME]];
        yield 'alliance id is a float' => [['name' => self::HOLLOW_NAME, 'alliance_id' => 99_000_021.5], ['name' => self::SABLE_NAME]];
        yield 'alliance without name' => [['name' => self::HOLLOW_NAME, 'alliance_id' => self::SABLE_ALLIANCE], []];
        yield 'alliance name is an integer' => [['name' => self::HOLLOW_NAME, 'alliance_id' => self::SABLE_ALLIANCE], ['name' => 7]];
        yield 'alliance name is null' => [['name' => self::HOLLOW_NAME, 'alliance_id' => self::SABLE_ALLIANCE], ['name' => null]];
    }

    public function testEachDistinctCorporationAndAllianceIsReadOnce(): void
    {
        $this->esiAnswers['/corporations/' . self::HOLLOW_CORPORATION . '/'] = ['name' => self::HOLLOW_NAME, 'alliance_id' => self::SABLE_ALLIANCE];
        $this->esiAnswers['/corporations/' . self::REACH_CORPORATION . '/'] = ['name' => self::REACH_NAME, 'alliance_id' => self::SABLE_ALLIANCE];
        $this->esiAnswers['/alliances/' . self::SABLE_ALLIANCE . '/'] = ['name' => self::SABLE_NAME];

        $affiliations = $this->resolver()->affiliations([self::HOLLOW_CORPORATION, self::REACH_CORPORATION, self::HOLLOW_CORPORATION]);

        $calls = $this->esiCalls;
        sort($calls);
        self::assertSame(
            [
                [self::HOLLOW_CORPORATION, self::REACH_CORPORATION],
                [self::HOLLOW_NAME, self::REACH_NAME],
                [
                    '/alliances/' . self::SABLE_ALLIANCE . '/',
                    '/corporations/' . self::HOLLOW_CORPORATION . '/',
                    '/corporations/' . self::REACH_CORPORATION . '/',
                ],
            ],
            [
                array_keys($affiliations),
                [$affiliations[self::HOLLOW_CORPORATION]->corporationName, $affiliations[self::REACH_CORPORATION]->corporationName],
                $calls,
            ],
        );
    }

    private function resolver(): MoonTaxAllianceResolver
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getWithCache')->willReturnCallback(function (string $endpoint): array {
            $this->esiCalls[] = $endpoint;
            $answer = $this->esiAnswers[$endpoint] ?? EsiApiException::notFound('No ESI answer prepared for ' . $endpoint, $endpoint);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });

        return new MoonTaxAllianceResolver($esiClient, new NullLogger());
    }
}
