<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Help;

use App\Scheduler\SyncScheduler;
use App\Service\ESI\AuthenticationService;
use App\Service\Help\HelpDescriptions;
use App\Service\Help\HelpTablesRenderer;
use App\Service\Help\ScheduledSyncReader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversNothing]
final class GeneratedHelpTablesUpToDateTest extends TestCase
{
    public function testCommittedHelpTablesMatchTheCode(): void
    {
        $helpDirectory = \dirname(__DIR__, 4).'/frontend/src/help';
        $reader = new ScheduledSyncReader([new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore()))]);
        $pages = (new HelpTablesRenderer(new HelpDescriptions()))->pages(AuthenticationService::getRequiredScopes(), $reader->read());

        foreach ($pages as $page => $expectedContent) {
            $path = $helpDirectory.'/'.$page;
            self::assertFileExists($path, \sprintf('%s is missing: run `php bin/console app:help:generate-tables`', $page));
            self::assertSame(
                $expectedContent,
                file_get_contents($path),
                \sprintf('%s is out of date: run `php bin/console app:help:generate-tables`', $page),
            );
        }
    }
}
