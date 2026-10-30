<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ScanLootSalesProcessorCorpProjectBoundariesTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const VELDSPAR_PROJECT_NAME = 'Veldspar Haul';
    private const VELDSPAR_REWARD_PER_CONTRIBUTION = 7.25;
    private const FIRST_PLAYER_CORPORATION_ID = 2000000;
    private const LAST_NPC_CORPORATION_ID = 1999999;

    public function testImportedOrDeclinedContributionIsNotProposedAgain(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400),
                    $this->contributorRecord(self::PILOT_THREE_ID, 'Pilot Three', 250),
                ],
                [self::EVE_CHARACTER_ID => 1000, self::PILOT_TWO_ID => 400, self::PILOT_THREE_ID => 250],
                new \DateTimeImmutable('-2 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            declinedTransactionIds: [-abs(crc32('a1b2c3d4-project_2112000002'))],
            corpProjectImports: [[
                'corpProjectId' => self::PROJECT_ID,
                'corpProjectCharacterId' => self::EVE_CHARACTER_ID,
                'corpProjectQuantity' => 1000,
                'transactionId' => -abs(crc32('a1b2c3d4-project_2112000001_1000')),
            ]],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([[-abs(crc32('a1b2c3d4-project_2112000003_250')), 'Pilot Three', 250, 3125.0]], $this->saleSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionOfZeroIsNeitherASaleNorAnError(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 0)], [self::EVE_CHARACTER_ID => 0], new \DateTimeImmutable('-2 days')),
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionOfZeroAskedWithoutTheProjectManagerRoleIsNeitherASaleNorAnError(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            $this->contributorsPageKey(self::PROJECT_ID, null) => $this->esiErrorResponse(403),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 0, new \DateTimeImmutable('-2 days')),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionOlderThanTheWindowOnARecentlyModifiedProjectIsNotProposed(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-40 days')),
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testNotFoundContributionOfAListedContributorIsAnEsiError(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->tritaniumDriveResponses(
                    [
                        $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                        $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 470),
                    ],
                    [self::EVE_CHARACTER_ID => 1000],
                    new \DateTimeImmutable('-2 days'),
                ),
                self::PROJECTS_PATH . '/' . self::PROJECT_ID . '/contribution/' . self::PILOT_TWO_ID => $this->esiErrorResponse(404),
            ],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, 12500.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot Two', 'esi_error', 404]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributorsPageWithoutItsListIsAnUnexpectedResponseOfTheProject(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            $this->contributorsPageKey(self::PROJECT_ID, null) => $this->jsonResponse(['cursor' => ['after' => 'contributors-after-1']]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testListedProjectWithoutLastModifiedIsAnUnexpectedResponseOfTheProject(): void
    {
        $processor = $this->createProcessor($this->projectListPages([
            ['id' => self::PROJECT_ID, 'name' => self::PROJECT_NAME, 'state' => 'Active'],
        ]));

        $result = $processor->process(null, new Post());

        $this->assertNotContains($this->contributorsPageKey(self::PROJECT_ID, null), $this->requestedPaths());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testTwoReadableCorporationsEachReadWithTheirOwnCharacterGiveBothSales(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
                ...$this->secondCorporationProjectResponses(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION, self::PILOT_THREE_ID, 240, new \DateTimeImmutable('-3 days')),
            ],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $secondCorporationProjectsPath = '/corporations/98000002/projects';
        $this->assertSame([
            ...array_map(
                static fn (string $path): array => [$path, 'Authorization: Bearer access-token-2112000001'],
                $this->tritaniumDrivePaths(self::EVE_CHARACTER_ID),
            ),
            [$secondCorporationProjectsPath . '?limit=100&state=All', 'Authorization: Bearer access-token-2112000003'],
            [$secondCorporationProjectsPath . '/e5f6a7b8-project/contributors?limit=100', 'Authorization: Bearer access-token-2112000003'],
            [$secondCorporationProjectsPath . '/e5f6a7b8-project', 'Authorization: Bearer access-token-2112000003'],
            [$secondCorporationProjectsPath . '/e5f6a7b8-project/contribution/2112000003', 'Authorization: Bearer access-token-2112000003'],
        ], array_map(null, $this->requestedPaths(), $this->requestedAuthorizations()));
        $this->assertSame(2, $result->scannedProjects);
        $this->assertSame([
            [self::PROJECT_NAME, 'Pilot One', 1000, 12500.0],
            [self::VELDSPAR_PROJECT_NAME, 'Pilot Three', 240, 1740.0],
        ], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testScanOfSevenDaysSkipsAProjectModifiedTenDaysAgo(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-10 days')),
                    $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', new \DateTimeImmutable('-5 days')),
                ]),
                ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 520)]),
                ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
                ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, 520, new \DateTimeImmutable('-5 days')),
            ],
            days: 7,
        );

        $result = $processor->process(null, new Post());

        $this->assertNotContains($this->contributorsPageKey(self::PROJECT_ID, null), $this->requestedPaths());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[self::VELDSPAR_PROJECT_NAME, 'Pilot One', 520, 3770.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testFirstPlayerCorporationIdIsRead(): void
    {
        $processor = $this->createProcessor(
            ['/corporations/2000000/projects?limit=100&state=All' => $this->jsonResponse([
                'projects' => [],
                'cursor' => ['after' => 'projects-after-end'],
            ])],
            characters: [$this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::FIRST_PLAYER_CORPORATION_ID, 'Boundary Player Corp')],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(['/corporations/2000000/projects?limit=100&state=All'], $this->requestedPaths());
        $this->assertSame(0, $result->scannedProjects);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testLastNpcCorporationIdIsNotRead(): void
    {
        $processor = $this->createProcessor(
            [],
            characters: [$this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::LAST_NPC_CORPORATION_ID, 'Boundary Npc Corp')],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->requestedPaths());
        $this->assertSame(0, $result->scannedProjects);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testPriceOfThreeContributionsAtOneTenthIsTheUnroundedProduct(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 3)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, 0.1),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 3, new \DateTimeImmutable('-2 days')),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 3, 0.30000000000000004]], $this->contributionSummaries($result->detectedSales));
    }
}
