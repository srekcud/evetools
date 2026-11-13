<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GenerateHelpTablesCommand;
use App\Scheduler\SyncScheduler;
use App\Service\ESI\AuthenticationService;
use App\Service\Help\HelpDescriptions;
use App\Service\Help\HelpTablesRenderer;
use App\Service\Help\ScheduledSyncReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(GenerateHelpTablesCommand::class)]
final class GenerateHelpTablesCommandTest extends TestCase
{
    private const GENERATED_PAGES = [
        'fr/data/esi-scopes.md',
        'fr/data/sync-frequencies.md',
        'en/data/esi-scopes.md',
        'en/data/sync-frequencies.md',
    ];

    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir().'/help-tables-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->outputDirectory);
    }

    public function testItWritesTheFourPagesIntoTheGivenDirectoryCreatingMissingFolders(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['--output-dir' => $this->outputDirectory]);

        self::assertSame(0, $exitCode);
        foreach (self::GENERATED_PAGES as $page) {
            self::assertFileExists($this->outputDirectory.'/'.$page);
            self::assertStringContainsString($page, $tester->getDisplay());
        }
    }

    public function testTheWrittenFilesAreTheRenderedPages(): void
    {
        (new CommandTester($this->command()))->execute(['--output-dir' => $this->outputDirectory]);

        $expected = $this->renderer()->pages(AuthenticationService::getRequiredScopes(), $this->reader()->read());
        foreach ($expected as $page => $content) {
            self::assertSame($content, file_get_contents($this->outputDirectory.'/'.$page), $page);
        }
    }

    public function testRunningTwiceLeavesTheSameFiles(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--output-dir' => $this->outputDirectory]);
        $first = file_get_contents($this->outputDirectory.'/fr/data/esi-scopes.md');

        $tester->execute(['--output-dir' => $this->outputDirectory]);

        self::assertSame($first, file_get_contents($this->outputDirectory.'/fr/data/esi-scopes.md'));
    }

    private function command(): GenerateHelpTablesCommand
    {
        return new GenerateHelpTablesCommand($this->renderer(), $this->reader(), $this->outputDirectory);
    }

    private function renderer(): HelpTablesRenderer
    {
        return new HelpTablesRenderer(new HelpDescriptions());
    }

    private function reader(): ScheduledSyncReader
    {
        return new ScheduledSyncReader(new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore())));
    }
}
