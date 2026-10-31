<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Pve\DetectedSaleResource;
use App\Service\Pve\CorpProjectContributionReader;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ScanLootSalesProcessor::class)]
#[CoversClass(CorpProjectContributionReader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ScanLootSalesProcessorCorpProjectRateLimitTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const VELDSPAR_PROJECT_NAME = 'Veldspar Haul';
    private const VELDSPAR_REWARD_PER_CONTRIBUTION = 7.25;
    private const SCORDITE_PROJECT_ID = 'f3a4b5c6-project';
    private const SCORDITE_PROJECT_NAME = 'Scordite Run';
    private const SCORDITE = 1228;
    private const SCORDITE_REWARD_PER_CONTRIBUTION = 4.75;
    private const MARKET_SALE_TRANSACTION_ID = 8200031;
    private const TRITANIUM_DRIVE_DETAILS_KEY = '/corporations/98000001/projects/a1b2c3d4-project';
    private const LIST_FIRST_PAGE = '/corporations/98000001/projects?limit=100&state=All';
    private const LIST_SECOND_PAGE = '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All';
    private const TRITANIUM_DRIVE_CONTRIBUTORS_FIRST_PAGE = '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100';
    private const TRITANIUM_DRIVE_CONTRIBUTORS_SECOND_PAGE = '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100';

    #[DataProvider('throttlingStatuses')]
    public function testThrottlingOfTheProjectListSurvivingTheReplayStopsTheReadingBeforeTheNextCorporation(int $throttlingStatus): void
    {
        $processor = $this->processorWith([self::LIST_FIRST_PAGE => $this->throttledTwice($throttlingStatus)]);

        $result = $processor->process(null, new Post());

        $this->assertSame([self::LIST_FIRST_PAGE, self::LIST_FIRST_PAGE], $this->requestedPaths());
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'esi_error', $throttlingStatus]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([[self::MARKET_SALE_TRANSACTION_ID, 'Pilot One', 6, 2460000.0]], $this->saleSummaries($result->detectedSales));
        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->charactersToReconnect);
    }

    #[DataProvider('throttlingStatuses')]
    public function testThrottlingOfAProjectDetailSurvivingTheReplayStopsTheReadingBeforeTheNextProject(int $throttlingStatus): void
    {
        $processor = $this->processorWith([self::TRITANIUM_DRIVE_DETAILS_KEY => $this->throttledTwice($throttlingStatus)]);

        $result = $processor->process(null, new Post());

        $this->assertSame([
            self::LIST_FIRST_PAGE,
            self::LIST_SECOND_PAGE,
            self::TRITANIUM_DRIVE_CONTRIBUTORS_FIRST_PAGE,
            self::TRITANIUM_DRIVE_CONTRIBUTORS_SECOND_PAGE,
            self::TRITANIUM_DRIVE_DETAILS_KEY,
            self::TRITANIUM_DRIVE_DETAILS_KEY,
        ], $this->requestedPaths());
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'esi_error', $throttlingStatus]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([[self::MARKET_SALE_TRANSACTION_ID, 'Pilot One', 6, 2460000.0]], $this->saleSummaries($result->detectedSales));
    }

    #[DataProvider('throttlingStatuses')]
    public function testThrottlingOfAContributionSurvivingTheReplayStopsTheReadingBeforeTheNextContributor(int $throttlingStatus): void
    {
        $processor = $this->processorWith([
            $this->contributionKey(self::EVE_CHARACTER_ID) => $this->throttledTwice($throttlingStatus),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([
            self::LIST_FIRST_PAGE,
            self::LIST_SECOND_PAGE,
            self::TRITANIUM_DRIVE_CONTRIBUTORS_FIRST_PAGE,
            self::TRITANIUM_DRIVE_CONTRIBUTORS_SECOND_PAGE,
            self::TRITANIUM_DRIVE_DETAILS_KEY,
            $this->contributionKey(self::EVE_CHARACTER_ID),
            $this->contributionKey(self::EVE_CHARACTER_ID),
        ], $this->requestedPaths());
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'esi_error', $throttlingStatus]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([[self::MARKET_SALE_TRANSACTION_ID, 'Pilot One', 6, 2460000.0]], $this->saleSummaries($result->detectedSales));
    }

    #[DataProvider('throttlingStatuses')]
    public function testThrottlingOfAContributionAskedWithoutTheProjectManagerRoleStopsTheReadingBeforeTheNextCharacter(int $throttlingStatus): void
    {
        $processor = $this->processorWith([
            self::TRITANIUM_DRIVE_CONTRIBUTORS_FIRST_PAGE => $this->esiErrorResponse(403),
            $this->contributionKey(self::EVE_CHARACTER_ID) => $this->throttledTwice($throttlingStatus),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([
            self::LIST_FIRST_PAGE,
            self::LIST_SECOND_PAGE,
            self::TRITANIUM_DRIVE_CONTRIBUTORS_FIRST_PAGE,
            self::TRITANIUM_DRIVE_DETAILS_KEY,
            $this->contributionKey(self::EVE_CHARACTER_ID),
            $this->contributionKey(self::EVE_CHARACTER_ID),
        ], $this->requestedPaths());
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'esi_error', $throttlingStatus]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([[self::MARKET_SALE_TRANSACTION_ID, 'Pilot One', 6, 2460000.0]], $this->saleSummaries($result->detectedSales));
    }

    public function testThrottlingAnsweredOnlyOnceIsReplayedAndTheReadingGoesOn(): void
    {
        $processor = $this->processorWith([
            $this->contributionKey(self::EVE_CHARACTER_ID) => [
                $this->throttledResponse(429),
                $this->jsonResponse(['contributed' => 1000, 'last_modified' => (new \DateTimeImmutable('-2 days'))->format('c')]),
            ],
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([
            [self::PROJECT_NAME, 'Pilot One', 1000, 12500.0],
            [self::PROJECT_NAME, 'Pilot Two', 400, 5000.0],
            [self::VELDSPAR_PROJECT_NAME, 'Pilot One', 600, 4350.0],
            [self::SCORDITE_PROJECT_NAME, 'Pilot Three', 240, 1140.0],
        ], $this->contributionSummaries(array_values(array_filter($result->detectedSales, static fn (DetectedSaleResource $sale): bool => $sale->source === 'corp_project'))));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public static function throttlingStatuses(): iterable
    {
        yield 'error limited' => [420];
        yield 'rate limited' => [429];
    }

    private function processorWith(array $throttledResponses): ScanLootSalesProcessor
    {
        return $this->createProcessor(
            [...$this->twoProjectsThenASecondCorporation(), ...$throttledResponses],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME),
            ],
            esiClock: new MockClock(),
        );
    }

    private function twoProjectsThenASecondCorporation(): array
    {
        $contributedAt = new \DateTimeImmutable('-2 days');

        return [
            '/characters/2112000001/wallet/transactions/' => $this->jsonResponse([[
                'transaction_id' => self::MARKET_SALE_TRANSACTION_ID,
                'is_buy' => false,
                'date' => (new \DateTimeImmutable('-3 days'))->format('c'),
                'type_id' => self::ROGUE_DRONE_INFESTATION_DATA,
                'quantity' => 6,
                'unit_price' => 410000.0,
            ]]),
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [
                $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400),
            ]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, $contributedAt),
            ...$this->contribution(self::PROJECT_ID, self::PILOT_TWO_ID, 400, $contributedAt),
            ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 600)]),
            ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, 600, $contributedAt),
            ...$this->secondCorporationProjectResponses(self::SCORDITE_PROJECT_ID, self::SCORDITE_PROJECT_NAME, self::SCORDITE, self::SCORDITE_REWARD_PER_CONTRIBUTION, self::PILOT_THREE_ID, 240, $contributedAt),
        ];
    }

    private function throttledTwice(int $throttlingStatus): array
    {
        return [$this->throttledResponse($throttlingStatus), $this->throttledResponse($throttlingStatus)];
    }

    private function throttledResponse(int $throttlingStatus): MockResponse
    {
        return new MockResponse('{"error":"throttled"}', [
            'http_code' => $throttlingStatus,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Retry-After' => '0',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }

    private function contributionKey(int $characterId): string
    {
        return self::TRITANIUM_DRIVE_DETAILS_KEY . '/contribution/' . $characterId;
    }
}
