<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sde;

use App\Entity\SdeBuildState;
use App\Repository\SdeBuildStateRepository;
use App\Service\Sde\SdeBlueprintImporter;
use App\Service\Sde\SdeDogmaImporter;
use App\Service\Sde\SdeImportService;
use App\Service\Sde\SdeInventoryImporter;
use App\Service\Sde\SdeLatestBuildFetcher;
use App\Service\Sde\SdeMapImporter;
use App\Service\Sde\SdePlanetaryImporter;
use App\Service\Sde\SdeReferenceImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SdeImportService::class)]
#[Group('issue-848')]
final class SdeImportServiceBuildRecordingTest extends TestCase
{
    private const LATEST_URL = 'https://developers.eveonline.com/static-data/tranquility/latest.jsonl';
    private const ARCHIVE_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';
    private const ANNOUNCED_BUILD = 3693310;
    private const ARCHIVE_BUILD = 3700000;
    private const ARCHIVE_RELEASE_DATE = '2026-11-14T09:30:00Z';

    private string $projectDir;
    private Filesystem $filesystem;
    private SdeBuildState $state;
    private int $saveCount;
    private SdeInventoryImporter&Stub $inventoryImporter;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/sde-import-build-recording-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->projectDir);
        $this->state = new SdeBuildState();
        $this->saveCount = 0;
        $this->inventoryImporter = $this->createStub(SdeInventoryImporter::class);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    public function testFullImportRecordsTheBuildReadFromTheExtractedArchiveAndTheImportTime(): void
    {
        $this->service()->downloadAndImport();

        self::assertSame(self::ARCHIVE_BUILD, $this->state->getImportedBuildNumber());
        self::assertSame('2026-11-14T09:30:00+00:00', $this->state->getImportedReleaseDate()?->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-11-13T15:30:00+00:00', $this->state->getImportedAt()?->format(\DateTimeInterface::ATOM));
        self::assertGreaterThanOrEqual(1, $this->saveCount);
    }

    public function testFullImportOverwritesThePreviouslyRecordedBuild(): void
    {
        $this->state
            ->setImportedBuildNumber(3213160)
            ->setImportedReleaseDate(new \DateTimeImmutable('2026-06-02T10:00:00Z'))
            ->setImportedAt(new \DateTimeImmutable('2026-06-03T08:00:00Z'));

        $this->service()->downloadAndImport();

        self::assertSame(self::ARCHIVE_BUILD, $this->state->getImportedBuildNumber());
        self::assertSame('2026-11-13T15:30:00+00:00', $this->state->getImportedAt()?->format(\DateTimeInterface::ATOM));
    }

    public function testPartialImportRecordsNothing(): void
    {
        $this->service()->downloadAndImport(null, ['planetary']);

        self::assertNull($this->state->getImportedBuildNumber());
        self::assertNull($this->state->getImportedReleaseDate());
        self::assertNull($this->state->getImportedAt());
        self::assertSame(0, $this->saveCount);
    }

    public function testPartialImportKeepsTheBuildRecordedByAPreviousFullImport(): void
    {
        $this->state
            ->setImportedBuildNumber(3213160)
            ->setImportedReleaseDate(new \DateTimeImmutable('2026-06-02T10:00:00Z'))
            ->setImportedAt(new \DateTimeImmutable('2026-06-03T08:00:00Z'));

        $this->service()->downloadAndImport(null, ['inventory']);

        self::assertSame(3213160, $this->state->getImportedBuildNumber());
        self::assertSame('2026-06-03T08:00:00+00:00', $this->state->getImportedAt()?->format(\DateTimeInterface::ATOM));
    }

    public function testFailedImportRecordsNothing(): void
    {
        $this->inventoryImporter->method('importTypes')->willThrowException(new \RuntimeException('types import failed'));

        try {
            $this->service()->downloadAndImport();
            self::fail('The import failure must reach the caller');
        } catch (\RuntimeException $failure) {
            self::assertSame('types import failed', $failure->getMessage());
        }

        self::assertNull($this->state->getImportedBuildNumber());
        self::assertNull($this->state->getImportedReleaseDate());
        self::assertNull($this->state->getImportedAt());
        self::assertSame(0, $this->saveCount);
    }

    private function service(): SdeImportService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            return match ($url) {
                self::LATEST_URL => new MockResponse(json_encode(['_key' => 'sde', 'buildNumber' => self::ANNOUNCED_BUILD, 'releaseDate' => '2026-11-12T11:07:00Z'], JSON_THROW_ON_ERROR)),
                self::ARCHIVE_URL => $this->archiveResponse(),
                default => self::fail('Unexpected HTTP request to ' . $url),
            };
        });

        $buildStateRepository = $this->createStub(SdeBuildStateRepository::class);
        $buildStateRepository->method('current')->willReturn($this->state);
        $buildStateRepository->method('save')->willReturnCallback(function (SdeBuildState $saved): void {
            self::assertSame($this->state, $saved);
            ++$this->saveCount;
        });

        return new SdeImportService(
            $httpClient,
            new NullLogger(),
            $this->inventoryImporter,
            $this->createStub(SdeMapImporter::class),
            $this->createStub(SdeBlueprintImporter::class),
            $this->createStub(SdeDogmaImporter::class),
            $this->createStub(SdeReferenceImporter::class),
            $this->createStub(SdePlanetaryImporter::class),
            new SdeLatestBuildFetcher($httpClient),
            $buildStateRepository,
            new MockClock('2026-11-13 15:30:00 UTC'),
            $this->projectDir,
        );
    }

    private function archiveResponse(): MockResponse
    {
        $archivePath = $this->projectDir . '/archive-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($archivePath, \ZipArchive::CREATE);
        $zip->addFromString('types.jsonl', 'types');
        $zip->addFromString('_sde.jsonl', json_encode(['_key' => 'sde', 'buildNumber' => self::ARCHIVE_BUILD, 'releaseDate' => self::ARCHIVE_RELEASE_DATE], JSON_THROW_ON_ERROR));
        $zip->close();

        return new MockResponse((string) file_get_contents($archivePath));
    }
}
