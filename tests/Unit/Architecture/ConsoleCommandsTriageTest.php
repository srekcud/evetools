<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-302')]
class ConsoleCommandsTriageTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function removedCommands(): iterable
    {
        yield 'token scopes migration already run in production' => ['App\Command\FixTokenScopesCommand'];
        yield 'structure search replaced by the structure search endpoint' => ['App\Command\SearchStructuresCommand'];
        yield 'asset structure names overlapped by resolve-all and the asset sync' => ['App\Command\ResolveStructureNamesCommand'];
    }

    /** @return iterable<string, array{string}> */
    public static function keptCommands(): iterable
    {
        yield 'resolve-all covers hostile structures, force and dry-run' => ['App\Command\ResolveAllStructuresCommand'];
        yield 'market cache clearing has no scheduled or admin replacement' => ['App\Command\ClearStructureMarketCacheCommand'];
    }

    #[DataProvider('removedCommands')]
    public function testRemovedCommandNoLongerExists(string $commandClass): void
    {
        $this->assertFalse(class_exists($commandClass));
    }

    #[DataProvider('keptCommands')]
    public function testKeptCommandStillExists(string $commandClass): void
    {
        $this->assertTrue(class_exists($commandClass));
    }
}
