<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sde;

use App\Service\Sde\SdeBuild;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeBuild::class)]
#[Group('issue-848')]
final class SdeBuildTest extends TestCase
{
    public function testReadsTheBuildNumberAndTheReleaseDateOfASdeLine(): void
    {
        $build = SdeBuild::fromJsonLine('{"_key":"sde","buildNumber":3693310,"releaseDate":"2026-11-12T11:07:00Z"}');

        self::assertNotNull($build);
        self::assertSame(3693310, $build->buildNumber);
        self::assertSame('2026-11-12T11:07:00+00:00', $build->releaseDate->format(\DateTimeInterface::ATOM));
    }

    public function testReadsOnlyTheFirstLineOfAFile(): void
    {
        $build = SdeBuild::fromJsonLine("{\"_key\":\"sde\",\"buildNumber\":3213160,\"releaseDate\":\"2026-06-02T10:00:00Z\"}\n{\"_key\":\"other\",\"buildNumber\":1}\n");

        self::assertNotNull($build);
        self::assertSame(3213160, $build->buildNumber);
        self::assertSame('2026-06-02T10:00:00+00:00', $build->releaseDate->format(\DateTimeInterface::ATOM));
    }

    #[DataProvider('unreadableLines')]
    public function testReturnsNullWhenTheLineCannotBeRead(string $line): void
    {
        self::assertNull(SdeBuild::fromJsonLine($line));
    }

    public static function unreadableLines(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['<html>maintenance</html>'];
        yield 'json scalar' => ['3693310'];
        yield 'no build number' => ['{"_key":"sde","releaseDate":"2026-11-12T11:07:00Z"}'];
        yield 'build number as string' => ['{"_key":"sde","buildNumber":"3693310","releaseDate":"2026-11-12T11:07:00Z"}'];
        yield 'build number as float' => ['{"_key":"sde","buildNumber":3693310.5,"releaseDate":"2026-11-12T11:07:00Z"}'];
        yield 'null build number' => ['{"_key":"sde","buildNumber":null,"releaseDate":"2026-11-12T11:07:00Z"}'];
        yield 'no release date' => ['{"_key":"sde","buildNumber":3693310}'];
        yield 'null release date' => ['{"_key":"sde","buildNumber":3693310,"releaseDate":null}'];
        yield 'release date as integer' => ['{"_key":"sde","buildNumber":3693310,"releaseDate":20261112}'];
        yield 'unreadable release date' => ['{"_key":"sde","buildNumber":3693310,"releaseDate":"last tuesday-ish"}'];
    }
}
