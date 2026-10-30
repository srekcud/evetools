<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Enum\PveIncomeType;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ScanLootSalesProcessorCorpProjectsTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const OTHER_CORPORATION_MEMBER_IDS = [2112000077, 2112000078];
    private const RED_LOOT_PROJECT_ID = 'e5f6a7b8-red-loot';
    private const RED_LOOT_PROJECT_NAME = 'Rogue Drone Sweep';
    private const PYERITE_PROJECT_ID = 'c9d0e1f2-pyerite';
    private const PYERITE_PROJECT_NAME = 'Pyerite Haul';
    private const RED_LOOT_REWARD_PER_CONTRIBUTION = 85000.0;
    private const CONTRIBUTED_QUANTITY = 1000;
    private const RED_LOOT_CONTRIBUTED_QUANTITY = 7;
    private const RED_LOOT_MISSING_FROM_SDE_CONTRIBUTED_QUANTITY = 5;
    private const RED_LOOT_MISSING_FROM_SDE_REWARD_PER_CONTRIBUTION = 64000.0;
    private const ACCESS_TOKEN = 'access-token-2112000001';
    private const COMPATIBILITY_DATE = '2025-12-16';
    private const NPC_CORPORATION_ID = 1000125;
    private const NPC_CORPORATION_NAME = 'Fictional Npc Corp';

    public function testCorpProjectContributionDetectedAsSaleWorthQuantityTimesRewardPerContribution(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor($this->corpProjectResponses($contributedAt));

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_1000')), $sale->transactionId);
        $this->assertSame(0, $sale->contractId);
        $this->assertSame(self::PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::TRITANIUM, $sale->typeId);
        $this->assertSame('1000x Tritanium', $sale->typeName);
        $this->assertSame(1000, $sale->quantity);
        $this->assertSame(12500.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('Pilot One', $sale->characterName);
        $this->assertSame('corp_project', $sale->source);
        $this->assertSame(self::PROJECT_NAME, $sale->projectName);
    }

    public function testCorpProjectRequestsSendCompatibilityDateAndCharacterBearerToken(): void
    {
        $processor = $this->createProcessor($this->corpProjectResponses(new \DateTimeImmutable('-2 days')));

        $processor->process(null, new Post());

        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $this->requestedPaths());
        foreach ($this->corporationProjectRequests as $request) {
            $this->assertSame(['X-Compatibility-Date: ' . self::COMPATIBILITY_DATE], $request['headers']['x-compatibility-date'] ?? null, $request['url']);
            $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $request['headers']['authorization'] ?? null, $request['url']);
        }
    }

    public function testConstructorNoLongerTakesAnHttpClient(): void
    {
        $constructor = (new \ReflectionClass(ScanLootSalesProcessor::class))->getConstructor();
        $this->assertNotNull($constructor);

        $httpClientParameters = array_filter(
            $constructor->getParameters(),
            static fn (\ReflectionParameter $parameter): bool => (string) $parameter->getType() === HttpClientInterface::class,
        );
        $this->assertSame([], array_values(array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $httpClientParameters,
        )));
    }

    public function testCorpProjectRequestsTargetUnversionedOriginOfConfiguredEsiBaseUrl(): void
    {
        $processor = $this->createProcessor($this->corpProjectResponses(new \DateTimeImmutable('-2 days')));

        $processor->process(null, new Post());

        $this->assertSame([
            'https://esi.test/corporations/98000001/projects?limit=100&state=All',
            'https://esi.test/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], array_column($this->corporationProjectRequests, 'normalizedUrl'));
    }

    public function testRateLimitedCorpProjectsListIsReplayedOnceAndContributionStillDetected(): void
    {
        $responses = $this->corpProjectResponses(new \DateTimeImmutable('-2 days'));
        $firstProjectsPageKey = $this->projectsPageKey(null);
        $responses[$firstProjectsPageKey] = [$this->rateLimitedResponse(), $responses[$firstProjectsPageKey]];
        $processor = $this->createProcessor($responses);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(12500.0, $result->detectedSales[0]->price);
        $this->assertSame(1000, $result->detectedSales[0]->quantity);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-243')]
    public function testUnavailableCorpProjectsListLeavesScannedProjectsUnknownAndListsTheEsiError(): void
    {
        $processor = $this->createProcessor([
            $this->projectsPageKey(null) => new MockResponse('{"error":"Internal error"}', ['http_code' => 500]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'esi_error', 500]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame(['/corporations/98000001/projects?limit=100&state=All'], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testRedLootDeliveredToACompletedProjectModifiedTenDaysAgoIsDetected(): void
    {
        $contributedAt = new \DateTimeImmutable('-11 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Completed', new \DateTimeImmutable('-10 days')),
            ]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(-abs(crc32(self::RED_LOOT_PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_' . self::RED_LOOT_CONTRIBUTED_QUANTITY)), $sale->transactionId);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::ROGUE_DRONE_INFESTATION_DATA, $sale->typeId);
        $this->assertSame('7x Rogue Drone Infestation Data', $sale->typeName);
        $this->assertSame(7, $sale->quantity);
        $this->assertSame(595000.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('Pilot One', $sale->characterName);
        $this->assertSame(self::RED_LOOT_PROJECT_NAME, $sale->projectName);
    }

    #[Group('issue-114')]
    public function testProjectOnlyOnTheSecondPageOfTheProjectListIsDetected(): void
    {
        $contributedAt = new \DateTimeImmutable('-4 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages(
                [$this->projectRecord(self::PYERITE_PROJECT_ID, self::PYERITE_PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day'))],
                [$this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Active', new \DateTimeImmutable('-3 days'))],
            ),
            ...$this->contributorPages(self::PYERITE_PROJECT_ID, [$this->contributorRecord(self::OTHER_CORPORATION_MEMBER_IDS[0], 'Hauler Seventy Seven', 40000)]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(2, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame('7x Rogue Drone Infestation Data', $result->detectedSales[0]->typeName);
        $this->assertSame(595000.0, $result->detectedSales[0]->price);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-2&limit=100&state=All',
            '/corporations/98000001/projects/c9d0e1f2-pyerite/contributors?limit=100',
            '/corporations/98000001/projects/c9d0e1f2-pyerite/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testCharacterOnlyOnTheSecondPageOfContributorsIsDetected(): void
    {
        $contributedAt = new \DateTimeImmutable('-5 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(
                self::RED_LOOT_PROJECT_ID,
                [
                    $this->contributorRecord(self::OTHER_CORPORATION_MEMBER_IDS[0], 'Hauler Seventy Seven', 12),
                    $this->contributorRecord(self::OTHER_CORPORATION_MEMBER_IDS[1], 'Hauler Seventy Eight', 3),
                ],
                [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)],
            ),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame(7, $result->detectedSales[0]->quantity);
        $this->assertSame(595000.0, $result->detectedSales[0]->price);
        $this->assertSame('Pilot One', $result->detectedSales[0]->characterName);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-2&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testDeletedProjectAndProjectModifiedBeforeTheWindowAreSkippedWithoutContributorsRequest(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PYERITE_PROJECT_ID, self::PYERITE_PROJECT_NAME, 'Expired', new \DateTimeImmutable('-45 days')),
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Deleted', new \DateTimeImmutable('-1 day')),
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-2 days')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, self::CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame(12500.0, $result->detectedSales[0]->price);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testProjectReturnedOnTwoPagesOfTheProjectListYieldsASingleSale(): void
    {
        $contributedAt = new \DateTimeImmutable('-6 days');
        $redLootProject = $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Completed', new \DateTimeImmutable('-3 days'));
        $processor = $this->createProcessor([
            ...$this->projectListPages([$redLootProject], [$redLootProject]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame(595000.0, $result->detectedSales[0]->price);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-2&limit=100&state=All',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-115')]
    public function testContributionOfLootMissingFromTheSdeIsDetectedWithTheExplicitLabel(): void
    {
        $contributedAt = new \DateTimeImmutable('-3 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Active', new \DateTimeImmutable('-2 days')),
            ]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_MISSING_FROM_SDE_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_MISSING_FROM_SDE_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_MISSING_FROM_SDE_CONTRIBUTED_QUANTITY, $contributedAt),
        ], [self::TRITANIUM => 'Tritanium']);

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::ROGUE_DRONE_INFESTATION_DATA, $sale->typeId);
        $this->assertSame('5x type 60459 (name missing from the SDE)', $sale->typeName);
        $this->assertSame(5, $sale->quantity);
        $this->assertSame(320000.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame(self::RED_LOOT_PROJECT_NAME, $sale->projectName);
    }

    #[Group('issue-243')]
    public function testInvalidFirstCharacterIsSkippedAndTheNextValidCharacterOfTheCorporationReadsTheProjects(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 250),
                    $this->contributorRecord(self::PILOT_THREE_ID, 'Pilot Three', 1000),
                ],
                [self::PILOT_THREE_ID => 1000],
                new \DateTimeImmutable('-2 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_FOUR_ID, 'Pilot Four', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_THREE_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000003'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_THREE_ID . '_1000')), 'Pilot Three', 1000, 12500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot One', 'Pilot Two', 'Pilot Four'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testCorporationWithoutValidCharacterLeavesScannedProjectsUnknownAndIsListed(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_FOUR_ID, 'Pilot Four', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->requestedPaths());
        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame(['Pilot One', 'Pilot Two', 'Pilot Three', 'Pilot Four'], $result->charactersToReconnect);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'no_valid_character', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testAllValidCharactersKeepTheFirstCharacterAsReaderWithNothingToReconnect(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_FOUR_ID, 'Pilot Four', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000001'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_1000')), 'Pilot One', 1000, 12500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame([], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testCorporationReadWithoutAnyProjectStillCountsZeroScannedProjects(): void
    {
        $processor = $this->createProcessor($this->projectListPages());

        $result = $processor->process(null, new Post());

        $this->assertSame(['/corporations/98000001/projects?limit=100&state=All'], $this->requestedPaths());
        $this->assertSame(['Authorization: Bearer access-token-2112000001'], $this->requestedAuthorizations());
        $this->assertSame(0, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testRefusedReaderIsListedToReconnectAndTheNextValidCharacterReadsTheProjects(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 1000)], [self::PILOT_TWO_ID => 1000], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            refusedCharacterIds: [self::EVE_CHARACTER_ID],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_TWO_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000002'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_TWO_ID . '_1000')), 'Pilot Two', 1000, 12500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot One'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testCorporationWhoseOnlyValidCharacterIsRefusedLeavesScannedProjectsUnknown(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
            ],
            refusedCharacterIds: [self::EVE_CHARACTER_ID],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->requestedPaths());
        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame(['Pilot One', 'Pilot Two'], $result->charactersToReconnect);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'no_valid_character', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testContributorRefusedDuringItsContributionIsListedToReconnectWithoutLosingTheOtherContribution(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400),
                ],
                [self::EVE_CHARACTER_ID => 1000],
                new \DateTimeImmutable('-2 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            refusedCharacterIds: [self::PILOT_TWO_ID],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000001'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_1000')), 'Pilot One', 1000, 12500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot Two'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testUnreadableCorporationMakesScannedProjectsUnknownEvenWhenAnotherCorporationIsRead(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_FOUR_ID, 'Pilot Four', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME, authValid: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000001'), $this->requestedAuthorizations());
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_1000')), 'Pilot One', 1000, 12500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertNull($result->scannedProjects);
        $this->assertSame(['Pilot Three', 'Pilot Four'], $result->charactersToReconnect);
        $this->assertSame([[self::SECOND_CORPORATION_NAME, null, null, 'no_valid_character', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testUnreadableCorporationListedFirstStillLeavesScannedProjectsUnknownAfterAReadCorporation(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 360)], [self::PILOT_TWO_ID => 360], new \DateTimeImmutable('-3 days')),
            characters: [
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_FOUR_ID, 'Pilot Four', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME, authValid: false),
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_TWO_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000002'), $this->requestedAuthorizations());
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_TWO_ID . '_360')), 'Pilot Two', 360, 4500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertNull($result->scannedProjects);
        $this->assertSame(['Pilot Three', 'Pilot Four', 'Pilot One'], $result->charactersToReconnect);
        $this->assertSame([[self::SECOND_CORPORATION_NAME, null, null, 'no_valid_character', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testInvalidCharacterOfAnNpcCorporationIsListedToReconnect(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::NPC_CORPORATION_ID, self::NPC_CORPORATION_NAME, authValid: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), $this->requestedPaths());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_1000')), 'Pilot One', 1000, 12500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot Two'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testCharacterWithoutTokenIsNeitherReaderNorContributorNorListedToReconnect(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 300),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 640),
                ],
                [self::PILOT_TWO_ID => 640],
                new \DateTimeImmutable('-4 days'),
            ),
            characters: [
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME, authValid: false, hasToken: false),
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false, hasToken: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_TWO_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000002'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_TWO_ID . '_640')), 'Pilot Two', 640, 8000.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame([], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testRefusedReaderWhoAlsoContributedIsListedOnceToReconnect(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 900),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 520),
                ],
                [self::PILOT_TWO_ID => 520],
                new \DateTimeImmutable('-1 day'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            refusedCharacterIds: [self::EVE_CHARACTER_ID],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_TWO_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000002'), $this->requestedAuthorizations());
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_TWO_ID . '_520')), 'Pilot Two', 520, 6500.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot One'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testServerErrorOnTheProjectListDoesNotTryTheNextValidCharacter(): void
    {
        $processor = $this->createProcessor(
            [$this->projectsPageKey(null) => new MockResponse('{"error":"Internal error"}', ['http_code' => 500])],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(['/corporations/98000001/projects?limit=100&state=All'], $this->requestedPaths());
        $this->assertSame(['Authorization: Bearer access-token-2112000001'], $this->requestedAuthorizations());
        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'esi_error', 500]], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testContributorRefusedDuringItsContributionDoesNotStopTheNextContributor(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400),
                    $this->contributorRecord(self::PILOT_THREE_ID, 'Pilot Three', 730),
                ],
                [self::PILOT_THREE_ID => 730],
                new \DateTimeImmutable('-3 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            refusedCharacterIds: [self::PILOT_TWO_ID],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_THREE_ID), $this->requestedPaths());
        $this->assertSame(
            [...array_fill(0, 5, 'Authorization: Bearer access-token-2112000001'), 'Authorization: Bearer access-token-2112000003'],
            $this->requestedAuthorizations(),
        );
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_THREE_ID . '_730')), 'Pilot Three', 730, 9125.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot Two'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    #[Group('issue-243')]
    public function testInvalidCharacterIsNeverAskedForAnAccessTokenByTheCorporationProjectScan(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 150),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 820),
                ],
                [self::PILOT_TWO_ID => 820],
                new \DateTimeImmutable('-2 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $walletTransactionsScan = [self::EVE_CHARACTER_ID, self::PILOT_TWO_ID, self::PILOT_TWO_ID];
        $contractsScan = [self::EVE_CHARACTER_ID, self::PILOT_TWO_ID, self::PILOT_TWO_ID];
        $corporationProjectsScan = array_fill(0, 7, self::PILOT_TWO_ID);
        $this->assertSame([...$walletTransactionsScan, ...$contractsScan, ...$corporationProjectsScan], $this->accessTokenRequests);
        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_TWO_ID), $this->requestedPaths());
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_TWO_ID . '_820')), 'Pilot Two', 820, 10250.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot One'], $result->charactersToReconnect);
    }

    #[Group('issue-243')]
    public function testCachedProjectListIsNotServedToARefusedReader(): void
    {
        $readerCache = new ArrayAdapter();
        $cachedProjectList = $readerCache->getItem('corp_projects_' . self::CORPORATION_ID);
        $cachedProjectList->set([$this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day'))]);
        $readerCache->save($cachedProjectList);
        $processor = $this->createProcessor(
            [
                ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 580)]),
                ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
                ...$this->contribution(self::PROJECT_ID, self::PILOT_TWO_ID, 580, new \DateTimeImmutable('-2 days')),
            ],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            refusedCharacterIds: [self::EVE_CHARACTER_ID],
            readerCache: $readerCache,
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(array_slice($this->tritaniumDrivePaths(self::PILOT_TWO_ID), 2), $this->requestedPaths());
        $this->assertSame(array_fill(0, 4, 'Authorization: Bearer access-token-2112000002'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[-abs(crc32(self::PROJECT_ID . '_' . self::PILOT_TWO_ID . '_580')), 'Pilot Two', 580, 7250.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame(['Pilot One'], $result->charactersToReconnect);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    private function corpProjectResponses(\DateTimeImmutable $contributedAt): array
    {
        return [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, self::CONTRIBUTED_QUANTITY, $contributedAt),
        ];
    }

    private function rateLimitedResponse(): MockResponse
    {
        return new MockResponse('{"error":"rate limited"}', [
            'http_code' => 429,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Retry-After' => '0',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }
}
