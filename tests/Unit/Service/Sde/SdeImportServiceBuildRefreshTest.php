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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SdeImportServiceBuildRefreshTest extends TestCase
{
    private const LATEST_URL = 'https://developers.eveonline.com/static-data/tranquility/latest.jsonl';
    private const ARCHIVE_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';
    private const OLD_BUILD = 3213160;
    private const NEW_BUILD = 3693310;

    private string $projectDir;
    private Filesystem $filesystem;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/sde-import-build-refresh-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->projectDir);
        $this->requestedUrls = [];
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    public function testDownloadsAgainWhenCcpPublishedANewerBuild(): void
    {
        $this->writeExtractedSde(self::OLD_BUILD, 'old types');
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD), $this->archiveResponse(self::NEW_BUILD, 'new types'));

        $service->downloadAndImport(null, ['planetary']);

        self::assertSame([self::LATEST_URL, self::ARCHIVE_URL], $this->requestedUrls);
        self::assertSame('new types', file_get_contents($this->sdeDir() . '/types.jsonl'));
        self::assertSame(self::NEW_BUILD, $this->localBuild());
    }

    public function testDropsFilesOfThePreviousBuildThatTheNewArchiveNoLongerShips(): void
    {
        $this->writeExtractedSde(self::OLD_BUILD, 'old types');
        file_put_contents($this->sdeDir() . '/removedInNewBuild.jsonl', 'stale');
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD), $this->archiveResponse(self::NEW_BUILD, 'new types'));

        $service->downloadAndImport(null, ['planetary']);

        self::assertFileDoesNotExist($this->sdeDir() . '/removedInNewBuild.jsonl');
    }

    public function testReusesExtractedFilesWhenBothBuildsMatch(): void
    {
        $this->writeExtractedSde(self::NEW_BUILD, 'kept types');
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD));

        $service->downloadAndImport(null, ['planetary']);

        self::assertSame([self::LATEST_URL], $this->requestedUrls);
        self::assertSame('kept types', file_get_contents($this->sdeDir() . '/types.jsonl'));
    }

    public function testDownloadsWhenNothingWasExtractedYet(): void
    {
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD), $this->archiveResponse(self::NEW_BUILD, 'fresh types'));

        $service->downloadAndImport(null, ['planetary']);

        self::assertSame('fresh types', file_get_contents($this->sdeDir() . '/types.jsonl'));
    }

    public function testDownloadsWhenExtractedFilesCarryNoBuildRecord(): void
    {
        $this->filesystem->mkdir($this->sdeDir());
        file_put_contents($this->sdeDir() . '/types.jsonl', 'unknown build types');
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD), $this->archiveResponse(self::NEW_BUILD, 'new types'));

        $service->downloadAndImport(null, ['planetary']);

        self::assertSame('new types', file_get_contents($this->sdeDir() . '/types.jsonl'));
    }

    public function testOutputNamesTheImportedBuild(): void
    {
        $this->writeExtractedSde(self::OLD_BUILD, 'old types');
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD), $this->archiveResponse(self::NEW_BUILD, 'new types'));
        $messages = [];

        $service->downloadAndImport(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }, ['planetary']);

        self::assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, (string) self::NEW_BUILD)));
    }

    public function testOutputNamesTheBuildOfReusedFiles(): void
    {
        $this->writeExtractedSde(self::NEW_BUILD, 'kept types');
        $service = $this->serviceWithResponses($this->latestResponse(self::NEW_BUILD));
        $messages = [];

        $service->downloadAndImport(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }, ['planetary']);

        self::assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, (string) self::NEW_BUILD)));
    }

    #[DataProvider('unreadableLatestBuilds')]
    public function testFailsExplicitlyAndKeepsOldFilesWhenCcpLatestBuildCannotBeRead(MockResponse $latestResponse): void
    {
        $this->writeExtractedSde(self::OLD_BUILD, 'old types');
        $service = $this->serviceWithResponses($latestResponse);

        $failure = null;
        try {
            $service->downloadAndImport(null, ['planetary']);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'The import must fail when the latest SDE build cannot be read');
        self::assertStringContainsString('latest', $failure->getMessage());
        self::assertSame('old types', file_get_contents($this->sdeDir() . '/types.jsonl'));
        self::assertNotContains(self::ARCHIVE_URL, $this->requestedUrls);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unreadableLatestBuilds(): iterable
    {
        yield 'server error' => [new MockResponse('', ['http_code' => 500])];
        yield 'not json' => [new MockResponse('<html>maintenance</html>')];
        yield 'no build number' => [new MockResponse('{"_key": "sde", "releaseDate": "2026-11-12T11:07:00Z"}')];
        yield 'empty body' => [new MockResponse('')];
    }

    public function testFailsExplicitlyWhenCcpLatestBuildIsUnreachable(): void
    {
        $this->writeExtractedSde(self::OLD_BUILD, 'old types');
        $service = $this->serviceWithResponses(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/latest/i');

        $service->downloadAndImport(null, ['planetary']);
    }

    private function sdeDir(): string
    {
        return $this->projectDir . '/var/sde';
    }

    private function localBuild(): int
    {
        $record = json_decode((string) file_get_contents($this->sdeDir() . '/_sde.jsonl'), true, 512, JSON_THROW_ON_ERROR);

        return $record['buildNumber'];
    }

    private function writeExtractedSde(int $build, string $typesContent): void
    {
        $this->filesystem->mkdir($this->sdeDir());
        file_put_contents($this->sdeDir() . '/types.jsonl', $typesContent);
        file_put_contents($this->sdeDir() . '/_sde.jsonl', $this->buildRecord($build));
    }

    private function buildRecord(int $build): string
    {
        return json_encode(['_key' => 'sde', 'buildNumber' => $build, 'releaseDate' => '2026-11-12T11:07:00Z'], JSON_THROW_ON_ERROR);
    }

    private function latestResponse(int $build): MockResponse
    {
        return new MockResponse($this->buildRecord($build));
    }

    private function archiveResponse(int $build, string $typesContent): MockResponse
    {
        $archivePath = $this->projectDir . '/archive-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($archivePath, \ZipArchive::CREATE);
        $zip->addFromString('types.jsonl', $typesContent);
        $zip->addFromString('_sde.jsonl', $this->buildRecord($build));
        $zip->close();

        return new MockResponse((string) file_get_contents($archivePath));
    }

    private function serviceWithResponses(MockResponse $latestResponse, ?MockResponse $archiveResponse = null): SdeImportService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($latestResponse, $archiveResponse): MockResponse {
            $this->requestedUrls[] = $url;

            return match ($url) {
                self::LATEST_URL => $latestResponse,
                self::ARCHIVE_URL => $archiveResponse ?? self::fail('Unexpected archive download'),
                default => self::fail('Unexpected HTTP request to ' . $url),
            };
        });

        $buildStateRepository = $this->createStub(SdeBuildStateRepository::class);
        $buildStateRepository->method('current')->willReturn(new SdeBuildState());

        return new SdeImportService(
            $httpClient,
            new NullLogger(),
            $this->createStub(SdeInventoryImporter::class),
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
}
