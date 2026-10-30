<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Pve\DetectedSaleResource;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ScanLootSalesProcessorCorpProjectErrorsTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const VELDSPAR_PROJECT_NAME = 'Veldspar Haul';

    public function testProjectListAnsweringServiceUnavailableLeavesScannedProjectsUnknownAndNamesTheCorporation(): void
    {
        $processor = $this->createProcessor([$this->projectsPageKey(null) => $this->esiErrorResponse(503)]);

        $result = $processor->process(null, new Post());

        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'esi_error', 503]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([], $result->charactersToReconnect);
    }

    public function testUnreachableEsiOnTheProjectListIsAnEsiErrorWithoutHttpStatus(): void
    {
        $processor = $this->createProcessor([$this->projectsPageKey(null) => $this->networkFailure()]);

        $result = $processor->process(null, new Post());

        $this->assertNull($result->scannedProjects);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'esi_error', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testProjectListPageWithoutItsProjectsIsAnUnexpectedResponseOfTheCorporation(): void
    {
        $processor = $this->createProcessor([
            $this->projectsPageKey(null) => $this->jsonResponse(['cursor' => ['after' => 'projects-after-1']]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertNull($result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testCharacterWithoutTheProjectsScopeIsSkippedAndTheNextCharacterReadsTheProjects(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400)], [self::PILOT_TWO_ID => 400], new \DateTimeImmutable('-2 days')),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::PILOT_TWO_ID), $this->requestedPaths());
        $this->assertSame(array_fill(0, 6, 'Authorization: Bearer access-token-2112000002'), $this->requestedAuthorizations());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', 400, 5000.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([], $result->charactersToReconnect);
    }

    public function testOnlyCharacterOfTheCorporationWithoutTheProjectsScopeIsReportedWithoutAnyProjectRequest(): void
    {
        $processor = $this->createProcessor(
            [],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->requestedPaths());
        $this->assertNull($result->scannedProjects);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'missing_projects_scope', null]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame([], $result->charactersToReconnect);
    }

    public function testValidCharacterWithoutTheProjectsScopeBesideAnInvalidOneReportsTheMissingScope(): void
    {
        $processor = $this->createProcessor(
            [],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, authValid: false),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->requestedPaths());
        $this->assertNull($result->scannedProjects);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'missing_projects_scope', null]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame(['Pilot One'], $result->charactersToReconnect);
    }

    public function testRefusedReaderFollowedByACharacterWithoutTheProjectsScopeReportsTheMissingScope(): void
    {
        $processor = $this->createProcessor(
            [],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: false),
            ],
            refusedCharacterIds: [self::EVE_CHARACTER_ID],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->requestedPaths());
        $this->assertNull($result->scannedProjects);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'missing_projects_scope', null]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame(['Pilot One'], $result->charactersToReconnect);
    }

    public function testUnreadableProjectDetailKeepsTheProjectCountedAndNamesIt(): void
    {
        $readerLogger = new BufferingLogger();
        $processor = $this->createProcessor(
            $this->tritaniumAndVeldsparResponses(veldsparDetails: [self::PROJECTS_PATH . '/' . self::VELDSPAR_PROJECT_ID => $this->esiErrorResponse(502)]),
            readerLogger: $readerLogger,
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(2, $result->scannedProjects);
        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, 12500.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::VELDSPAR_PROJECT_NAME, null, 'esi_error', 502]], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertNotContains('/corporations/98000001/projects/e5f6a7b8-project/contribution/2112000001', $this->requestedPaths());
        $this->assertCount(1, $this->warningsOf($readerLogger));
    }

    public function testContributorsServerErrorKeepsTheProjectCountedAndNamesIt(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            $this->contributorsPageKey(self::PROJECT_ID, null) => $this->esiErrorResponse(500),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'esi_error', 500]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testReaderWithoutTheProjectManagerRoleAsksEachCharacterForItsOwnContribution(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                ]),
                $this->contributorsPageKey(self::PROJECT_ID, null) => $this->esiErrorResponse(403),
                ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
                ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, new \DateTimeImmutable('-2 days')),
                $this->contributionKey(self::PROJECT_ID, self::PILOT_TWO_ID) => $this->esiErrorResponse(404),
            ],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([
            [$this->contributionKey(self::PROJECT_ID, self::EVE_CHARACTER_ID), 'Authorization: Bearer access-token-2112000001'],
            [$this->contributionKey(self::PROJECT_ID, self::PILOT_TWO_ID), 'Authorization: Bearer access-token-2112000002'],
        ], $this->contributionRequests());
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, 12500.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testServerErrorOnAContributionAskedWithoutTheProjectManagerRoleIsListed(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                ]),
                $this->contributorsPageKey(self::PROJECT_ID, null) => $this->esiErrorResponse(403),
                ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
                ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 820, new \DateTimeImmutable('-3 days')),
                $this->contributionKey(self::PROJECT_ID, self::PILOT_TWO_ID) => $this->esiErrorResponse(500),
            ],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 820, 10250.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot Two', 'esi_error', 500]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testProjectWithoutContributionRewardYieldsASaleOfUnknownPrice(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
            self::PROJECTS_PATH . '/' . self::PROJECT_ID => $this->jsonResponse([
                'configuration' => ['deliver_item' => ['items' => [['type_id' => self::TRITANIUM]]]],
            ]),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, new \DateTimeImmutable('-2 days')),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, null]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionObjectWithoutRewardPerContributionYieldsASaleOfUnknownPrice(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 640)]),
            self::PROJECTS_PATH . '/' . self::PROJECT_ID => $this->jsonResponse([
                'configuration' => ['deliver_item' => ['items' => [['type_id' => self::TRITANIUM]]]],
                'contribution' => ['submission_multiplier' => 1.5],
            ]),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 640, new \DateTimeImmutable('-2 days')),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 640, null]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testExplicitZeroRewardKeepsASaleWorthZero(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 310)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, 0.0),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 310, new \DateTimeImmutable('-2 days')),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 310, 0.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionWithoutContributedIsAnUnexpectedResponseAndNoSale(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveWithContributionBody(['last_modified' => (new \DateTimeImmutable('-2 days'))->format('c')]));

        $result = $processor->process(null, new Post());

        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionGivingAQuantityAliasInsteadOfContributedIsAnUnexpectedResponse(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveWithContributionBody([
            'quantity' => 1000,
            'last_modified' => (new \DateTimeImmutable('-2 days'))->format('c'),
        ]));

        $result = $processor->process(null, new Post());

        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionWithAnUnreadableDateIsAnUnexpectedResponseAndNoSale(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveWithContributionBody(['contributed' => 1000, 'last_modified' => 'not-a-date']));

        $result = $processor->process(null, new Post());

        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testServerErrorOnOneContributionKeepsTheOtherContributionAndNamesTheCharacter(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                ]),
                ...$this->contributorPages(self::PROJECT_ID, [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400),
                ]),
                ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
                ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, new \DateTimeImmutable('-2 days')),
                $this->contributionKey(self::PROJECT_ID, self::PILOT_TWO_ID) => $this->esiErrorResponse(500),
            ],
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, 12500.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot Two', 'esi_error', 500]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributorWithoutTheProjectsScopeIsNotAskedAndIsReported(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 450),
                ],
                [self::EVE_CHARACTER_ID => 1000],
                new \DateTimeImmutable('-2 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: false),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), $this->requestedPaths());
        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, 12500.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot Two', 'missing_projects_scope', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionWithoutLastModifiedIsDatedByTheProjectLastModifiedNotByToday(): void
    {
        $projectLastModified = (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->setTime(10, 0);
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', $projectLastModified),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            $this->contributionKey(self::PROJECT_ID, self::EVE_CHARACTER_ID) => $this->jsonResponse(['contributed' => 1000]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedSales);
        $this->assertSame($projectLastModified->format('Y-m-d'), $result->detectedSales[0]->dateIssued);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testErrorsAreListedInCharacterOrderThenInProjectListOrder(): void
    {
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                    $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', new \DateTimeImmutable('-3 days')),
                ]),
                $this->contributorsPageKey(self::PROJECT_ID, null) => $this->esiErrorResponse(500),
                ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 600)]),
                self::PROJECTS_PATH . '/' . self::VELDSPAR_PROJECT_ID => $this->esiErrorResponse(502),
            ],
            characters: [
                $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME, authValid: false),
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
        );

        $result = $processor->process(null, new Post());

        $this->assertNull($result->scannedProjects);
        $this->assertSame([
            [self::SECOND_CORPORATION_NAME, null, null, 'no_valid_character', null],
            [self::CORPORATION_NAME, self::PROJECT_NAME, null, 'esi_error', 500],
            [self::CORPORATION_NAME, self::VELDSPAR_PROJECT_NAME, null, 'esi_error', 502],
        ], $this->errorSummaries($result->corporationProjectErrors));
        $this->assertSame(['Pilot Three'], $result->charactersToReconnect);
    }

    public function testWithoutLootTypesTheProjectsAreStillReadButNotTheTransactionsNorTheContracts(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [self::EVE_CHARACTER_ID => 1000], new \DateTimeImmutable('-2 days')),
            lootTypeIds: [],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([], $this->characterRequests);
        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), $this->requestedPaths());
        $this->assertTrue($result->noLootTypesConfigured);
        $this->assertNull($result->scannedTransactions);
        $this->assertNull($result->scannedContracts);
        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 1000, 12500.0]], $this->contributionSummaries($result->detectedSales));
    }

    public function testWithoutLootTypesACorporationWithoutProjectGivesZeroScannedProjectsAndNoError(): void
    {
        $processor = $this->createProcessor($this->projectListPages(), lootTypeIds: []);

        $result = $processor->process(null, new Post());

        $this->assertSame(['/corporations/98000001/projects?limit=100&state=All'], $this->requestedPaths());
        $this->assertTrue($result->noLootTypesConfigured);
        $this->assertNull($result->scannedTransactions);
        $this->assertNull($result->scannedContracts);
        $this->assertSame(0, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testWithoutLootTypesAnUnavailableProjectListIsStillReported(): void
    {
        $processor = $this->createProcessor([$this->projectsPageKey(null) => $this->esiErrorResponse(503)], lootTypeIds: []);

        $result = $processor->process(null, new Post());

        $this->assertTrue($result->noLootTypesConfigured);
        $this->assertNull($result->scannedProjects);
        $this->assertSame([[self::CORPORATION_NAME, null, null, 'esi_error', 503]], $this->errorSummaries($result->corporationProjectErrors));
    }

    private function tritaniumAndVeldsparResponses(array $veldsparDetails): array
    {
        return [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', new \DateTimeImmutable('-3 days')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, new \DateTimeImmutable('-2 days')),
            ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 600)]),
            ...$veldsparDetails,
        ];
    }

    private function tritaniumDriveWithContributionBody(array $contributionBody): array
    {
        return [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            $this->contributionKey(self::PROJECT_ID, self::EVE_CHARACTER_ID) => $this->jsonResponse($contributionBody),
        ];
    }

    private function contributionKey(string $projectId, int $characterId): string
    {
        return self::PROJECTS_PATH . '/' . $projectId . '/contribution/' . $characterId;
    }

    private function contributionRequests(): array
    {
        return array_values(array_map(
            static fn (array $request): array => [$request['key'], $request['headers']['authorization'][0] ?? null],
            array_filter(
                $this->corporationProjectRequests,
                static fn (array $request): bool => str_contains($request['key'], '/contribution/'),
            ),
        ));
    }

    private function contributionSummaries(array $detectedSales): array
    {
        return array_map(
            static fn (DetectedSaleResource $sale): array => [$sale->projectName, $sale->characterName, $sale->quantity, $sale->price],
            $detectedSales,
        );
    }

    private function warningsOf(BufferingLogger $logger): array
    {
        return array_values(array_filter(
            $logger->cleanLogs(),
            static fn (array $record): bool => $record[0] === 'warning',
        ));
    }
}
