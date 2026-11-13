<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ImportMoonCompositionsCommand;
use App\Entity\CorporationMiningExtraction;
use App\Service\MoonChunk\MoonCompositionImporter;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MoonChunk\SeedsMoonChunkMarket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ImportMoonCompositionsCommand::class)]
#[Group('issue-853')]
#[Group('issue-859')]
final class ImportMoonCompositionsCommandTest extends IntegrationTestCase
{
    use SeedsMoonChunkMarket;

    private const string SHEET_URL = 'https://sheets.example.test/export?format=csv&gid=0';
    private const string FIXTURE = __DIR__ . '/../../Fixtures/MoonCompositionSheet/community-sheet.csv';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMoonOreSde($this->em);
        $this->persistExtraction(40_301_001, 'Veyra II - Moon 1');
        $this->persistExtraction(40_301_003, 'Veyra II - Moon 3');
        $this->persistExtraction(40_399_999, 'Unlisted I - Moon 1');
        $this->em->flush();
    }

    public function testItDownloadsTheGivenUrlImportsTheMoonsAndPrintsTheSummaryWithTheRejectedRows(): void
    {
        $requestedUrls = [];
        $tester = $this->tester(new MockHttpClient(static function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $method . ' ' . $url;

            return new MockResponse(file_get_contents(self::FIXTURE) ?: '');
        }));

        $exitCode = $tester->execute(['url' => self::SHEET_URL]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['GET ' . self::SHEET_URL], $requestedUrls);
        self::assertStringContainsString('Moons in database: 3', $display);
        self::assertStringContainsString('Matched in the sheet: 2', $display);
        self::assertStringContainsString('Imported: 1', $display);
        self::assertStringContainsString('Skipped (already scanned): 0', $display);
        self::assertStringContainsString('Not found in the sheet: 1', $display);
        self::assertStringContainsString('Rejected: 1', $display);
        self::assertStringContainsString('Veyra II - Moon 3', $display);
        self::assertStringContainsString('Cinnabar', $display);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM moon_compositions'));
    }

    public function testAFailedDownloadStopsTheCommandWithoutImportingAnything(): void
    {
        $tester = $this->tester(new MockHttpClient(new MockResponse('', ['http_code' => 404])));

        $exitCode = $tester->execute(['url' => self::SHEET_URL]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('404', $tester->getDisplay());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM moon_compositions'));
    }

    public function testASheetWithoutTheExpectedColumnsStopsTheCommandWithoutImportingAnything(): void
    {
        $tester = $this->tester(new MockHttpClient(new MockResponse("Name,Region\nVeyra - II - Moon 1,Northreach\n")));

        $exitCode = $tester->execute(['url' => self::SHEET_URL]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Moon Name', $tester->getDisplay());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM moon_compositions'));
    }

    private function tester(MockHttpClient $httpClient): CommandTester
    {
        return new CommandTester(new ImportMoonCompositionsCommand($httpClient, self::getContainer()->get(MoonCompositionImporter::class)));
    }

    private function persistExtraction(int $moonId, string $moonName): void
    {
        $start = new \DateTimeImmutable('2026-11-01T18:00:00+00:00');
        $this->em->persist(
            (new CorporationMiningExtraction())
                ->setCorporationId(98_262_101)
                ->setStructureId(1_030_000_000_001)
                ->setMoonId($moonId)
                ->setMoonName($moonName)
                ->setExtractionStartTime($start)
                ->setChunkArrivalTime($start->modify('+3 days'))
                ->setNaturalDecayTime($start->modify('+3 days 3 hours')),
        );
    }
}
