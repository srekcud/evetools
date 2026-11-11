<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\AdminChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-654')]
final class AdminCheckerListedIdsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function listedValues(): iterable
    {
        yield 'single id' => ['90000001', [90000001]];
        yield 'several ids keep their order' => ['90000002,90000001,90000003', [90000002, 90000001, 90000003]];
        yield 'spaces around ids are trimmed' => [' 90000001 , 90000002 ', [90000001, 90000002]];
        yield 'blank value means no admin' => ['', []];
        yield 'stray commas are ignored' => [',90000001,,', [90000001]];
        yield 'non numeric entries are ignored' => ['abc,90000001', [90000001]];
        yield 'duplicates are listed once' => ['90000001,90000001', [90000001]];
    }

    /**
     * @param list<int> $expectedIds
     */
    #[DataProvider('listedValues')]
    public function testListedEveCharacterIdsReturnsTheConfiguredIds(string $configured, array $expectedIds): void
    {
        self::assertSame($expectedIds, (new AdminChecker($configured))->listedEveCharacterIds());
    }
}
