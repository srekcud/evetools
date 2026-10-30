<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\PveSyncService;
use App\Tests\Unit\State\Processor\Pve\CorpProjectsEsiSimulation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PveSyncService::class)]
#[Group('issue-243')]
final class PveSyncServiceCorpProjectsTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const VELDSPAR_PROJECT_NAME = 'Veldspar Haul';
    private const VELDSPAR_REWARD_PER_CONTRIBUTION = 7.25;
    private const PILOT_ONE_JOURNAL_KEY = '/characters/2112000001/wallet/journal/?page=1';

    private UserPveSettings $settings;

    private BufferingLogger $logger;

    private array $initialCorpProjectImports = [];

    private ?\Throwable $corpProjectImportsFailure = null;

    private array $persisted = [];

    private array $entityManagerCalls = [];

    private array $publishedSyncEvents = [];

    protected function setUp(): void
    {
        $this->settings = new UserPveSettings();
        $this->settings->setLootTypeIds([self::TRITANIUM]);
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testSyncImportsAPendingContributionAsACorpProjectIncome(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $user = $this->userOf($this->pilotOne());
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)],
            [self::EVE_CHARACTER_ID => 1000],
            $contributedAt,
        ));

        $results = $service->syncAll($user);

        $this->assertSame(1, $results['corpProjects']);
        $this->assertSame([], $results['errors']);
        $this->assertSame([[
            'user' => $user,
            'type' => PveIncomeType::CorpProject,
            'amount' => 12500.0,
            'date' => $this->utc($contributedAt),
            'description' => 'Tritanium Drive: 1000x Tritanium',
            'transactionId' => -abs(crc32('a1b2c3d4-project_2112000001_1000')),
            'corpProjectId' => self::PROJECT_ID,
            'corpProjectCharacterId' => self::EVE_CHARACTER_ID,
            'corpProjectQuantity' => 1000,
        ]], $this->corpProjectIncomeRows());
        $this->assertSame(1, $this->completedEvent()['data']['corpProjects']);
        $this->assertSame('0 bounties, 0 sales, 0 contracts, 0 expenses', $this->completedEvent()['message']);
        $this->assertSame([], $this->warningTexts());
    }

    public function testSyncDoesNotImportTheSameCumulativeContributionTwice(): void
    {
        $user = $this->userOf($this->pilotOne());
        $service = $this->createService([
            ...$this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [], new \DateTimeImmutable('-2 days')),
            ...$this->contributionPasses(self::PROJECT_ID, self::EVE_CHARACTER_ID, new \DateTimeImmutable('-2 days'), 1000, 1000),
        ]);
        $service->syncAll($user);

        $secondPass = $service->syncAll($user);

        $this->assertSame(0, $secondPass['corpProjects']);
        $this->assertSame([], $secondPass['errors']);
        $this->assertSame([1000], array_column($this->corpProjectIncomeRows(), 'corpProjectQuantity'));
        $this->assertSame(0, $this->completedEvent()['data']['corpProjects']);
    }

    public function testSyncImportsOnlyTheIncreaseOfACumulativeContribution(): void
    {
        $contributedAt = new \DateTimeImmutable('-1 day');
        $user = $this->userOf($this->pilotOne());
        $service = $this->createService([
            ...$this->tritaniumDriveResponses([$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [], $contributedAt),
            ...$this->contributionPasses(self::PROJECT_ID, self::EVE_CHARACTER_ID, $contributedAt, 1000, 1600),
        ]);
        $service->syncAll($user);

        $secondPass = $service->syncAll($user);

        $this->assertSame(1, $secondPass['corpProjects']);
        $this->assertSame([
            [12500.0, 'Tritanium Drive: 1000x Tritanium', -abs(crc32('a1b2c3d4-project_2112000001_1000')), 1000],
            [7500.0, 'Tritanium Drive: 600x Tritanium', -abs(crc32('a1b2c3d4-project_2112000001_1600')), 600],
        ], $this->amountDescriptionTransactionAndQuantityRows());
    }

    public function testSyncReportsAContributionBelowTheImportedQuantityWithoutImportingIt(): void
    {
        $this->initialCorpProjectImports = [$this->pairedImport(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000)];
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 800)],
            [self::EVE_CHARACTER_ID => 800],
            new \DateTimeImmutable('-2 days'),
        ));

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(0, $results['corpProjects']);
        $this->assertSame(['corpProjects: Fictional Mining Corp / Tritanium Drive / Pilot One: contribution_below_imported'], $results['errors']);
        $this->assertSame(0, $results['failedCharacters']);
        $this->assertSame([], $this->persisted);
        $this->assertWarnedOnce('corpProjects: Fictional Mining Corp / Tritanium Drive / Pilot One: contribution_below_imported');
    }

    #[DataProvider('projectDetailsWithoutAPositiveReward')]
    public function testSyncLeavesAProjectWithoutAPositiveRewardToTheManualScan(array $tritaniumDriveDetails): void
    {
        $contributedAt = new \DateTimeImmutable('-3 days');
        $service = $this->createService([
            ...$this->tritaniumAndVeldsparListedTogether($contributedAt),
            self::PROJECTS_PATH . '/' . self::PROJECT_ID => $this->jsonResponse($tritaniumDriveDetails),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, $contributedAt),
            ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, 840, $contributedAt),
        ]);

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(1, $results['corpProjects']);
        $this->assertSame([], $results['errors']);
        $this->assertSame([
            [6090.0, 'Veldspar Haul: 840x Veldspar', -abs(crc32('e5f6a7b8-project_2112000001_840')), 840],
        ], $this->amountDescriptionTransactionAndQuantityRows());
        $this->assertSame([], $this->warningTexts());
    }

    public static function projectDetailsWithoutAPositiveReward(): iterable
    {
        $deliveredTritanium = ['deliver_item' => ['items' => [['type_id' => self::TRITANIUM]]]];

        yield 'no contribution object' => [['configuration' => $deliveredTritanium]];
        yield 'contribution without reward' => [['configuration' => $deliveredTritanium, 'contribution' => ['submission_multiplier' => 1]]];
        yield 'zero reward' => [['configuration' => $deliveredTritanium, 'contribution' => ['reward_per_contribution' => 0]]];
    }

    public function testSyncReadsOnlyProjectsModifiedWithinTheLastThirtyDays(): void
    {
        $recentlyContributedAt = new \DateTimeImmutable('-29 days');
        $service = $this->createService([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-45 days')),
                $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', $recentlyContributedAt),
            ]),
            ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 840)]),
            ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, 840, $recentlyContributedAt),
        ]);

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(1, $results['corpProjects']);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/e5f6a7b8-project/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-project',
            '/corporations/98000001/projects/e5f6a7b8-project/contribution/2112000001',
        ], $this->requestedPaths());
        $this->assertSame([
            [6090.0, 'Veldspar Haul: 840x Veldspar', -abs(crc32('e5f6a7b8-project_2112000001_840')), 840],
        ], $this->amountDescriptionTransactionAndQuantityRows());
    }

    public function testUnreadableProjectListKeepsTheOtherStepsAndIsReportedAsACorpProjectError(): void
    {
        $syncStartedAt = new \DateTimeImmutable();
        $service = $this->createService([
            self::PILOT_ONE_JOURNAL_KEY => $this->jsonResponse([$this->bounty(7300001, 2_450_000.0)]),
            $this->projectsPageKey(null) => $this->esiErrorResponse(503),
        ]);

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame([
            'bounties' => 1,
            'lootSales' => 0,
            'lootContracts' => 0,
            'corpProjects' => 0,
            'expenses' => 0,
            'errors' => ['corpProjects: Fictional Mining Corp: esi_error (HTTP 503)'],
            'failedCharacters' => 0,
            'tokenizedCharacters' => 1,
        ], $results);
        $this->assertSame([[PveIncomeType::Bounty, 2_450_000.0, 7300001]], array_map(
            static fn (PveIncome $income): array => [$income->getType(), $income->getAmount(), $income->getJournalEntryId()],
            $this->persisted,
        ));
        $this->assertGreaterThanOrEqual($syncStartedAt, $this->settings->getLastSyncAt());
        $this->assertSame('completed', $this->completedEvent()['status']);
        $this->assertSame(0, $this->completedEvent()['data']['corpProjects']);
        $this->assertSame(0, $this->completedEvent()['data']['failedCharacters']);
        $this->assertWarnedOnce('corpProjects: Fictional Mining Corp: esi_error (HTTP 503)');
    }

    #[DataProvider('corpProjectReadErrors')]
    public function testCorpProjectReadErrorIsReportedOnceInTheSyncErrors(\Closure $esiResponses, string $expectedError): void
    {
        $service = $this->createService($esiResponses($this));

        $results = $service->syncAll($this->userOf(
            $this->pilotOne(hasProjectsScope: $expectedError !== 'corpProjects: Fictional Mining Corp: missing_projects_scope'),
        ));

        $this->assertSame([$expectedError], $results['errors']);
        $this->assertSame(0, $results['corpProjects']);
        $this->assertSame(0, $results['failedCharacters']);
        $this->assertSame([], $this->persisted);
        $this->assertWarnedOnce($expectedError);
    }

    public static function corpProjectReadErrors(): iterable
    {
        yield 'project list unreachable' => [
            static fn (self $test): array => [$test->projectsPageKey(null) => $test->networkFailure()],
            'corpProjects: Fictional Mining Corp: esi_error',
        ];
        yield 'reader without the projects scope' => [
            static fn (self $test): array => [],
            'corpProjects: Fictional Mining Corp: missing_projects_scope',
        ];
        yield 'project details in error' => [
            static fn (self $test): array => [
                ...$test->projectListPages([$test->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day'))]),
                ...$test->contributorPages(self::PROJECT_ID, [$test->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
                self::PROJECTS_PATH . '/' . self::PROJECT_ID => $test->esiErrorResponse(502),
            ],
            'corpProjects: Fictional Mining Corp / Tritanium Drive: esi_error (HTTP 502)',
        ];
        yield 'contribution without contributed' => [
            static fn (self $test): array => [
                ...$test->tritaniumDriveResponses([$test->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)], [], new \DateTimeImmutable('-1 day')),
                self::PROJECTS_PATH . '/' . self::PROJECT_ID . '/contribution/' . self::EVE_CHARACTER_ID => $test->jsonResponse(['last_modified' => '2026-10-28T09:00:00Z']),
            ],
            'corpProjects: Fictional Mining Corp / Tritanium Drive / Pilot One: unexpected_response',
        ];
    }

    public function testContributionReadErrorDoesNotFailTheCharacterNorTheOtherContributions(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $service = $this->createService([
            ...$this->tritaniumDriveResponses(
                [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000), $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400)],
                [self::EVE_CHARACTER_ID => 1000],
                $contributedAt,
            ),
            self::PROJECTS_PATH . '/' . self::PROJECT_ID . '/contribution/' . self::PILOT_TWO_ID => $this->esiErrorResponse(500),
        ]);

        $results = $service->syncAll($this->userOf($this->pilotOne(), $this->pilotTwo()));

        $this->assertSame(1, $results['corpProjects']);
        $this->assertSame(['corpProjects: Fictional Mining Corp / Tritanium Drive / Pilot Two: esi_error (HTTP 500)'], $results['errors']);
        $this->assertSame(0, $results['failedCharacters']);
        $this->assertSame(2, $results['tokenizedCharacters']);
        $this->assertSame([self::EVE_CHARACTER_ID], array_column($this->corpProjectIncomeRows(), 'corpProjectCharacterId'));
        $this->assertSame('completed', $this->completedEvent()['status']);
        $this->assertSame('0 bounties, 0 sales, 0 contracts, 0 expenses', $this->completedEvent()['message']);
    }

    public function testFailureOfTheCorpProjectStepKeepsTheOtherStepsAndTheSync(): void
    {
        $syncStartedAt = new \DateTimeImmutable();
        $this->corpProjectImportsFailure = new \RuntimeException('corp project imports unavailable');
        $service = $this->createService([
            self::PILOT_ONE_JOURNAL_KEY => $this->jsonResponse([$this->bounty(7300002, 1_875_000.0)]),
        ]);

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(1, $results['bounties']);
        $this->assertSame(0, $results['corpProjects']);
        $this->assertSame(['corpProjects: corp project imports unavailable'], $results['errors']);
        $this->assertSame(0, $results['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $this->settings->getLastSyncAt());
        $this->assertSame('completed', $this->completedEvent()['status']);
        $this->assertSame([10, 35, 60, 75, 85], array_column($this->progressEvents(), 0));
    }

    public function testSyncAnnouncesTheCorpProjectStepBeforeReadingTheProjects(): void
    {
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)],
            [self::EVE_CHARACTER_ID => 1000],
            new \DateTimeImmutable('-2 days'),
        ));

        $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame([
            [10, 'Fetching bounties...', 0],
            [35, 'Fetching loot sales...', 0],
            [60, 'Analyzing loot contracts...', 0],
            [75, 'Fetching corporation projects...', 0],
            [85, 'Fetching expenses...', 6],
        ], $this->progressEvents());
    }

    public function testSyncImportsTheContributionsOfTwoCharactersToTheSameProject(): void
    {
        $contributedAt = new \DateTimeImmutable('-4 days');
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000), $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400)],
            [self::EVE_CHARACTER_ID => 1000, self::PILOT_TWO_ID => 400],
            $contributedAt,
        ));

        $results = $service->syncAll($this->userOf($this->pilotOne(), $this->pilotTwo()));

        $this->assertSame(2, $results['corpProjects']);
        $this->assertSame([
            [self::PROJECT_ID, self::EVE_CHARACTER_ID, 12500.0, 'Tritanium Drive: 1000x Tritanium', -abs(crc32('a1b2c3d4-project_2112000001_1000'))],
            [self::PROJECT_ID, self::PILOT_TWO_ID, 5000.0, 'Tritanium Drive: 400x Tritanium', -abs(crc32('a1b2c3d4-project_2112000002_400'))],
        ], array_map(
            static fn (array $row): array => [$row['corpProjectId'], $row['corpProjectCharacterId'], $row['amount'], $row['description'], $row['transactionId']],
            $this->corpProjectIncomeRows(),
        ));
        $this->assertSame(2, $this->completedEvent()['data']['corpProjects']);
    }

    public function testSyncImportsTheContributionsToTwoProjectsAtTheirOwnReward(): void
    {
        $contributedAt = new \DateTimeImmutable('-3 days');
        $service = $this->createService([
            ...$this->tritaniumAndVeldsparListedTogether($contributedAt),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, $contributedAt),
            ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, 840, $contributedAt),
        ]);

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(2, $results['corpProjects']);
        $this->assertSame([
            [self::PROJECT_ID, 12500.0, 'Tritanium Drive: 1000x Tritanium', 1000],
            [self::VELDSPAR_PROJECT_ID, 6090.0, 'Veldspar Haul: 840x Veldspar', 840],
        ], array_map(
            static fn (array $row): array => [$row['corpProjectId'], $row['amount'], $row['description'], $row['corpProjectQuantity']],
            $this->corpProjectIncomeRows(),
        ));
    }

    public function testSyncDatesAContributionWithoutLastModificationAtTheProjectLastModification(): void
    {
        $projectModifiedAt = new \DateTimeImmutable('-5 days');
        $service = $this->createService([
            ...$this->projectListPages([$this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', $projectModifiedAt)]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            self::PROJECTS_PATH . '/' . self::PROJECT_ID . '/contribution/' . self::EVE_CHARACTER_ID => $this->jsonResponse(['contributed' => 1000]),
        ]);

        $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame([$this->utc($projectModifiedAt)], array_column($this->corpProjectIncomeRows(), 'date'));
    }

    public function testSyncImportsTheRemainderOfAnImportMadeBeforeQuantityTracking(): void
    {
        $this->initialCorpProjectImports = [$this->legacyImport(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000)];
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1450)],
            [self::EVE_CHARACTER_ID => 1450],
            new \DateTimeImmutable('-2 days'),
        ));

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(1, $results['corpProjects']);
        $this->assertSame([
            [5625.0, 'Tritanium Drive: 450x Tritanium', -abs(crc32('a1b2c3d4-project_2112000001_1450')), 450],
        ], $this->amountDescriptionTransactionAndQuantityRows());
    }

    public function testSyncReportsAnImportMadeBeforeQuantityTrackingWithAnUnknownQuantity(): void
    {
        $this->initialCorpProjectImports = [$this->legacyImport(self::PROJECT_ID, self::EVE_CHARACTER_ID, null)];
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1450)],
            [self::EVE_CHARACTER_ID => 1450],
            new \DateTimeImmutable('-2 days'),
        ));

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(0, $results['corpProjects']);
        $this->assertSame(['corpProjects: Fictional Mining Corp / Tritanium Drive / Pilot One: legacy_import_quantity_unknown'], $results['errors']);
        $this->assertSame([], $this->persisted);
    }

    public function testSyncSkipsAContributionDeclinedBeforeQuantityTracking(): void
    {
        $this->settings->addDeclinedLootSaleTransactionId(-abs(crc32('a1b2c3d4-project_2112000001')));
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1200)],
            [self::EVE_CHARACTER_ID => 1200],
            new \DateTimeImmutable('-2 days'),
        ));

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(0, $results['corpProjects']);
        $this->assertSame([], $results['errors']);
        $this->assertSame([], $this->persisted);
    }

    public function testSyncCountsTheDeclinedQuantityAsAlreadyAccounted(): void
    {
        $this->initialCorpProjectImports = [$this->pairedImport(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000)];
        $this->settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::EVE_CHARACTER_ID, 600);
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1900)],
            [self::EVE_CHARACTER_ID => 1900],
            new \DateTimeImmutable('-2 days'),
        ));

        $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame([
            [3750.0, 'Tritanium Drive: 300x Tritanium', -abs(crc32('a1b2c3d4-project_2112000001_1900')), 300],
        ], $this->amountDescriptionTransactionAndQuantityRows());
    }

    public function testSyncImportsCorpProjectContributionsWithoutAnyLootTypeConfigured(): void
    {
        $this->settings->setLootTypeIds([]);
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)],
            [self::EVE_CHARACTER_ID => 1000],
            new \DateTimeImmutable('-2 days'),
        ));

        $results = $service->syncAll($this->userOf($this->pilotOne()));

        $this->assertSame(1, $results['corpProjects']);
        $this->assertSame([12500.0], array_column($this->corpProjectIncomeRows(), 'amount'));
    }

    public function testCorpProjectStepFlushesOnceAfterPersistingItsIncomes(): void
    {
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000), $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400)],
            [self::EVE_CHARACTER_ID => 1000, self::PILOT_TWO_ID => 400],
            new \DateTimeImmutable('-2 days'),
        ));

        $imported = $service->syncCorpProjectContributions($this->userOf($this->pilotOne(), $this->pilotTwo()));

        $this->assertSame(2, $imported);
        $this->assertSame(['persist', 'persist', 'flush'], $this->entityManagerCalls);
    }

    public function testCorpProjectStepDoesNotFlushWhenNothingIsPending(): void
    {
        $this->initialCorpProjectImports = [$this->pairedImport(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000)];
        $service = $this->createService($this->tritaniumDriveResponses(
            [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)],
            [self::EVE_CHARACTER_ID => 1000],
            new \DateTimeImmutable('-2 days'),
        ));

        $imported = $service->syncCorpProjectContributions($this->userOf($this->pilotOne()));

        $this->assertSame(0, $imported);
        $this->assertSame([], $this->entityManagerCalls);
    }

    private function createService(array $esiResponsesByKey): PveSyncService
    {
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findCorpProjectImports')->willReturnCallback(fn (): array => $this->corpProjectImports());
        $incomeRepository->method('getImportedTransactionIds')->willReturnCallback(fn (): array => array_values(array_filter(
            array_map(static fn (PveIncome $income): ?int => $income->getTransactionId(), $this->persistedIncomes()),
            static fn (?int $transactionId): bool => $transactionId !== null,
        )));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->entityManagerCalls[] = 'persist';
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = 'flush';
        });

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $event = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
            $event['corporationProjectRequestsSoFar'] = count($this->corporationProjectRequests);
            $this->publishedSyncEvents[] = $event;

            return 'urn:uuid:test';
        });

        return $this->createPveSyncService(
            $esiResponsesByKey,
            $incomeRepository,
            $this->settings,
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            $this->logger,
        );
    }

    private function corpProjectImports(): array
    {
        if ($this->corpProjectImportsFailure !== null) {
            throw $this->corpProjectImportsFailure;
        }

        return [
            ...$this->initialCorpProjectImports,
            ...array_map(static fn (PveIncome $income): array => [
                'corpProjectId' => $income->getCorpProjectId(),
                'corpProjectCharacterId' => $income->getCorpProjectCharacterId(),
                'corpProjectQuantity' => $income->getCorpProjectQuantity(),
                'transactionId' => $income->getTransactionId(),
            ], $this->persistedCorpProjectIncomes()),
        ];
    }

    private function pairedImport(string $projectId, int $characterId, int $quantity): array
    {
        return [
            'corpProjectId' => $projectId,
            'corpProjectCharacterId' => $characterId,
            'corpProjectQuantity' => $quantity,
            'transactionId' => -abs(crc32("{$projectId}_{$characterId}_{$quantity}")),
        ];
    }

    private function legacyImport(string $projectId, int $characterId, ?int $quantity): array
    {
        return [
            'corpProjectId' => null,
            'corpProjectCharacterId' => null,
            'corpProjectQuantity' => $quantity,
            'transactionId' => -abs(crc32("{$projectId}_{$characterId}")),
        ];
    }

    private function contributionPasses(string $projectId, int $characterId, \DateTimeImmutable $contributedAt, int ...$contributedByPass): array
    {
        return [
            self::PROJECTS_PATH . '/' . $projectId . '/contribution/' . $characterId => array_map(
                fn (int $contributed): MockResponse => $this->jsonResponse(['contributed' => $contributed, 'last_modified' => $contributedAt->format('c')]),
                $contributedByPass,
            ),
        ];
    }

    private function tritaniumAndVeldsparListedTogether(\DateTimeImmutable $modifiedAt): array
    {
        return [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', $modifiedAt),
                $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', $modifiedAt),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
            ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 840)]),
        ];
    }

    private function bounty(int $journalEntryId, float $amount): array
    {
        return [
            'id' => $journalEntryId,
            'ref_type' => 'bounty_prizes',
            'amount' => $amount,
            'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
        ];
    }

    private function pilotOne(bool $hasProjectsScope = true): Character
    {
        return $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: $hasProjectsScope);
    }

    private function pilotTwo(): Character
    {
        return $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME);
    }

    private function userOf(Character ...$characters): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection($characters));

        return $user;
    }

    private function persistedIncomes(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $entity): bool => $entity instanceof PveIncome));
    }

    private function persistedCorpProjectIncomes(): array
    {
        return array_values(array_filter(
            $this->persistedIncomes(),
            static fn (PveIncome $income): bool => $income->getType() === PveIncomeType::CorpProject,
        ));
    }

    private function corpProjectIncomeRows(): array
    {
        return array_map(fn (PveIncome $income): array => [
            'user' => $income->getUser(),
            'type' => $income->getType(),
            'amount' => $income->getAmount(),
            'date' => $this->utc($income->getDate()),
            'description' => $income->getDescription(),
            'transactionId' => $income->getTransactionId(),
            'corpProjectId' => $income->getCorpProjectId(),
            'corpProjectCharacterId' => $income->getCorpProjectCharacterId(),
            'corpProjectQuantity' => $income->getCorpProjectQuantity(),
        ], $this->persistedCorpProjectIncomes());
    }

    private function amountDescriptionTransactionAndQuantityRows(): array
    {
        return array_map(
            static fn (array $row): array => [$row['amount'], $row['description'], $row['transactionId'], $row['corpProjectQuantity']],
            $this->corpProjectIncomeRows(),
        );
    }

    private function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function progressEvents(): array
    {
        return array_values(array_map(
            static fn (array $event): array => [$event['progress'], $event['message'], $event['corporationProjectRequestsSoFar']],
            array_filter($this->publishedSyncEvents, static fn (array $event): bool => $event['status'] === 'in_progress'),
        ));
    }

    private function completedEvent(): array
    {
        $this->assertNotEmpty($this->publishedSyncEvents);

        return $this->publishedSyncEvents[array_key_last($this->publishedSyncEvents)];
    }

    private function warningTexts(): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record[1] . ' ' . json_encode($record[2], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            array_filter($this->logger->cleanLogs(), static fn (array $record): bool => $record[0] === 'warning'),
        ));
    }

    private function assertWarnedOnce(string $corpProjectError): void
    {
        $warnings = $this->warningTexts();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($corpProjectError, $warnings[0]);
    }
}
