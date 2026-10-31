<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pve;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\UserPveSettings;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Pve\CorpProjectContribution;
use App\Service\Pve\CorpProjectContributionAccounting;
use App\Service\Pve\CorpProjectContributionReader;
use App\Service\Pve\CorpProjectContributionReading;
use App\Service\Pve\CorporationProjectReadError;
use App\Tests\Unit\State\Processor\Pve\CorpProjectsEsiSimulation;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CorpProjectContributionReader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class CorpProjectContributionReaderTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const WINDOW_START = '2026-10-01T00:00:00+00:00';
    private const AFTER_THE_WINDOW_START = '2026-10-03T08:00:00+00:00';
    private const BEFORE_THE_WINDOW_START = '2026-09-30T23:59:59+00:00';
    private const TRITANIUM_DRIVE_DETAILS_KEY = '/corporations/98000001/projects/a1b2c3d4-project';

    public function testProjectModifiedExactlyAtTheStartOfTheWindowIsRead(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::WINDOW_START),
                [self::EVE_CHARACTER_ID => [700, new \DateTimeImmutable(self::WINDOW_START)]],
            ),
            [$this->pilotOne()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', self::TRITANIUM, 700, 8750.0]], $this->contributionRows($reading));
        $this->assertSame(1, $reading->scannedProjects);
    }

    public function testContributionMadeExactlyAtTheStartOfTheWindowIsProposed(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [self::EVE_CHARACTER_ID => [640, new \DateTimeImmutable(self::WINDOW_START)]],
            ),
            [$this->pilotOne()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', self::TRITANIUM, 640, 8000.0]], $this->contributionRows($reading));
    }

    public function testContributorWithoutTheProjectsScopeListedFirstDoesNotHideTheNextContributor(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [self::PILOT_TWO_ID => [450, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)]],
                contributorIds: [self::EVE_CHARACTER_ID, self::PILOT_TWO_ID],
            ),
            [$this->pilotOne(hasProjectsScope: false), $this->pilotTwo()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', self::TRITANIUM, 450, 5625.0]], $this->contributionRows($reading));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'missing_projects_scope', null]], $this->errorRows($reading));
    }

    public function testZeroContributionOfTheFirstContributorDoesNotHideTheNextContributor(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [
                    self::EVE_CHARACTER_ID => [0, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                    self::PILOT_TWO_ID => [380, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                ],
            ),
            [$this->pilotOne(), $this->pilotTwo()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', self::TRITANIUM, 380, 4750.0]], $this->contributionRows($reading));
        $this->assertSame([], $this->errorRows($reading));
    }

    public function testContributionOlderThanTheWindowOfTheFirstContributorDoesNotHideTheNextContributor(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [
                    self::EVE_CHARACTER_ID => [900, new \DateTimeImmutable(self::BEFORE_THE_WINDOW_START)],
                    self::PILOT_TWO_ID => [260, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                ],
            ),
            [$this->pilotOne(), $this->pilotTwo()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', self::TRITANIUM, 260, 3250.0]], $this->contributionRows($reading));
        $this->assertSame([], $this->errorRows($reading));
    }

    public function testLegacyImportOfUnknownQuantityOfTheFirstContributorDoesNotHideTheNextContributor(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [
                    self::EVE_CHARACTER_ID => [1100, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                    self::PILOT_TWO_ID => [520, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                ],
            ),
            [$this->pilotOne(), $this->pilotTwo()],
            corpProjectImports: [[
                'corpProjectId' => null,
                'corpProjectCharacterId' => null,
                'corpProjectQuantity' => null,
                'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID)),
            ]],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', self::TRITANIUM, 520, 6500.0]], $this->contributionRows($reading));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'legacy_import_quantity_unknown', null]], $this->errorRows($reading));
    }

    public function testCumulBelowTheImportOfTheFirstContributorDoesNotHideTheNextContributor(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [
                    self::EVE_CHARACTER_ID => [1000, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                    self::PILOT_TWO_ID => [610, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                ],
            ),
            [$this->pilotOne(), $this->pilotTwo()],
            corpProjectImports: [[
                'corpProjectId' => self::PROJECT_ID,
                'corpProjectCharacterId' => self::EVE_CHARACTER_ID,
                'corpProjectQuantity' => 1200,
                'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID . '_1200')),
            ]],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', self::TRITANIUM, 610, 7625.0]], $this->contributionRows($reading));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'contribution_below_imported', null]], $this->errorRows($reading));
    }

    public function testNotFoundContributionOfTheFirstCharacterWithoutTheProjectManagerRoleDoesNotHideTheNextCharacter(): void
    {
        $reading = $this->read(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)),
                ]),
                $this->contributorsPageKey(self::PROJECT_ID, null) => $this->esiErrorResponse(403),
                ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
                self::TRITANIUM_DRIVE_DETAILS_KEY . '/contribution/' . self::EVE_CHARACTER_ID => $this->esiErrorResponse(404),
                ...$this->contribution(self::PROJECT_ID, self::PILOT_TWO_ID, 330, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)),
            ],
            [$this->pilotOne(), $this->pilotTwo()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot Two', self::TRITANIUM, 330, 4125.0]], $this->contributionRows($reading));
        $this->assertSame([], $this->errorRows($reading));
    }

    public function testProjectDetailErrorKeepsTheMissingScopeErrorOfAContributor(): void
    {
        $reading = $this->read(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)),
                ]),
                ...$this->contributorPages(self::PROJECT_ID, [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 800),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 200),
                ]),
                self::TRITANIUM_DRIVE_DETAILS_KEY => $this->esiErrorResponse(500),
            ],
            [$this->pilotOne(), $this->pilotTwo(hasProjectsScope: false)],
        );

        $this->assertSame([], $this->contributionRows($reading));
        $this->assertSame([
            [self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot Two', 'missing_projects_scope', null],
            [self::CORPORATION_NAME, self::PROJECT_NAME, null, 'esi_error', 500],
        ], $this->errorRows($reading));
        $this->assertSame(1, $reading->scannedProjects);
    }

    public function testProjectWithoutDeliveredItemGivesAContributionOfNoType(): void
    {
        $reading = $this->read(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)),
                ]),
                ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 300)]),
                self::TRITANIUM_DRIVE_DETAILS_KEY => $this->jsonResponse(['contribution' => ['reward_per_contribution' => 9.5]]),
                ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 300, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)),
            ],
            [$this->pilotOne()],
        );

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 0, 300, 2850.0]], $this->contributionRows($reading));
    }

    public function testReaderRefusedOnTheProjectDetailReadsNoContributionOfThatProject(): void
    {
        $reading = $this->read(
            $this->tritaniumDrive(
                new \DateTimeImmutable(self::AFTER_THE_WINDOW_START),
                [
                    self::EVE_CHARACTER_ID => [750, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                    self::PILOT_TWO_ID => [470, new \DateTimeImmutable(self::AFTER_THE_WINDOW_START)],
                ],
            ),
            [$this->pilotOne(), $this->pilotTwo()],
            acceptedAccessTokenCallsByCharacterId: [self::EVE_CHARACTER_ID => 5],
        );

        $this->assertSame([], $this->contributionRows($reading));
        $this->assertSame([], $this->errorRows($reading));
        $this->assertSame(1, $reading->scannedProjects);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
        ], $this->requestedPaths());
    }

    public function testReaderRefusedOnTheContributorsStillCountsTheProjectAsScanned(): void
    {
        $readerCache = new ArrayAdapter();
        $cachedProjectList = $readerCache->getItem('corp_projects_' . self::CORPORATION_ID);
        $cachedProjectList->set([$this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable(self::AFTER_THE_WINDOW_START))]);
        $readerCache->save($cachedProjectList);

        $reading = $this->read(
            [],
            [$this->pilotOne()],
            readerCache: $readerCache,
            acceptedAccessTokenCallsByCharacterId: [self::EVE_CHARACTER_ID => 1],
        );

        $this->assertSame(1, $reading->scannedProjects);
        $this->assertSame([], $this->contributionRows($reading));
        $this->assertSame([], $this->errorRows($reading));
    }

    public function testProjectListAndDetailAreKeptFifteenMinutesAndContributorsFiveMinutes(): void
    {
        $cacheClock = new MockClock();
        $reader = $this->reader(
            [
                ...$this->tritaniumDrivePlannedThreeTimes(),
                ...$this->contributionsReadFourTimes(),
            ],
            new ArrayAdapter(clock: $cacheClock),
        );
        $user = $this->createUser([$this->pilotOne()]);
        $from = new \DateTimeImmutable(self::WINDOW_START);

        $reader->read($user, $from);
        $requestsOfTheFirstRead = count($this->corporationProjectRequests);
        $cacheClock->sleep(299);
        $readAfterFourMinutes = $reader->read($user, $from);
        $requestsUpToTheSecondRead = count($this->corporationProjectRequests);
        $cacheClock->sleep(2);
        $reader->read($user, $from);
        $requestsUpToTheThirdRead = count($this->corporationProjectRequests);
        $cacheClock->sleep(600);
        $reader->read($user, $from);

        $paths = $this->requestedPaths();
        $contributionPath = self::TRITANIUM_DRIVE_DETAILS_KEY . '/contribution/' . self::EVE_CHARACTER_ID;
        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), array_slice($paths, 0, $requestsOfTheFirstRead));
        $this->assertSame([$contributionPath], array_slice($paths, $requestsOfTheFirstRead, $requestsUpToTheSecondRead - $requestsOfTheFirstRead));
        $this->assertSame([
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            $contributionPath,
        ], array_slice($paths, $requestsUpToTheSecondRead, $requestsUpToTheThirdRead - $requestsUpToTheSecondRead));
        $this->assertSame($this->tritaniumDrivePaths(self::EVE_CHARACTER_ID), array_slice($paths, $requestsUpToTheThirdRead));
        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', self::TRITANIUM, 560, 7000.0]], $this->contributionRows($readAfterFourMinutes));
    }

    private function read(
        array $esiResponsesByKey,
        array $characters,
        ArrayAdapter $readerCache = new ArrayAdapter(),
        array $acceptedAccessTokenCallsByCharacterId = [],
        array $corpProjectImports = [],
    ): CorpProjectContributionReading {
        return $this->reader($esiResponsesByKey, $readerCache, $acceptedAccessTokenCallsByCharacterId, $corpProjectImports)
            ->read($this->createUser($characters), new \DateTimeImmutable(self::WINDOW_START));
    }

    private function reader(
        array $esiResponsesByKey,
        ArrayAdapter $readerCache,
        array $acceptedAccessTokenCallsByCharacterId = [],
        array $corpProjectImports = [],
    ): CorpProjectContributionReader {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(
            fn (EveToken $token): string => $this->accessTokenOf($token, [], $acceptedAccessTokenCallsByCharacterId),
        );

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findCorpProjectImports')->willReturn($corpProjectImports);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn(new UserPveSettings());

        return new CorpProjectContributionReader(
            esiClient: new EsiClient($this->createSimulatedEsi($esiResponsesByKey), new TagAwareAdapter(new ArrayAdapter()), $tokenManager, 'https://esi.test/latest', new NullLogger(), new MockClock()),
            tokenManager: $tokenManager,
            cache: $readerCache,
            logger: new NullLogger(),
            contributionAccounting: new CorpProjectContributionAccounting(
                incomeRepository: $incomeRepository,
                settingsRepository: $settingsRepository,
            ),
        );
    }

    private function tritaniumDrive(\DateTimeImmutable $projectLastModified, array $contributionByCharacterId, ?array $contributorIds = null): array
    {
        $contributorIds ??= array_keys($contributionByCharacterId);
        $responses = [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', $projectLastModified),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, array_map(
                fn (int $characterId): array => $this->contributorRecord($characterId, 'Contributor ' . $characterId, 0),
                $contributorIds,
            )),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
        ];
        foreach ($contributionByCharacterId as $characterId => [$contributedQuantity, $contributedAt]) {
            $responses = [...$responses, ...$this->contribution(self::PROJECT_ID, $characterId, $contributedQuantity, $contributedAt)];
        }

        return $responses;
    }

    private function tritaniumDrivePlannedThreeTimes(): array
    {
        $responses = [];
        foreach (range(1, 3) as $plannedRead) {
            foreach ($this->tritaniumDrive(new \DateTimeImmutable(self::AFTER_THE_WINDOW_START), [], [self::EVE_CHARACTER_ID]) as $key => $response) {
                $responses[$key][] = $response;
            }
        }

        return $responses;
    }

    private function contributionsReadFourTimes(): array
    {
        $contributedAt = new \DateTimeImmutable(self::AFTER_THE_WINDOW_START);
        $key = self::TRITANIUM_DRIVE_DETAILS_KEY . '/contribution/' . self::EVE_CHARACTER_ID;

        return [$key => array_map(
            fn (): MockResponse => $this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 560, $contributedAt)[$key],
            range(1, 4),
        )];
    }

    private function pilotOne(bool $hasProjectsScope = true): Character
    {
        return $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: $hasProjectsScope);
    }

    private function pilotTwo(bool $hasProjectsScope = true): Character
    {
        return $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME, hasProjectsScope: $hasProjectsScope);
    }

    private function contributionRows(CorpProjectContributionReading $reading): array
    {
        return array_map(
            static fn (CorpProjectContribution $contribution): array => [
                $contribution->projectName,
                $contribution->characterName,
                $contribution->typeId,
                $contribution->pendingQuantity(),
                $contribution->pendingValue(),
            ],
            $reading->contributions,
        );
    }

    private function errorRows(CorpProjectContributionReading $reading): array
    {
        return array_map(
            static fn (CorporationProjectReadError $error): array => [
                $error->corporationName,
                $error->projectName,
                $error->characterName,
                $error->reason->value,
                $error->httpStatus,
            ],
            $reading->errors,
        );
    }
}
