<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sde;

use App\Entity\SdeBuildState;
use App\Enum\SdeBuildStatus;
use App\Service\Sde\SdeBuildStatusResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeBuildStatusResolver::class)]
#[Group('issue-848')]
final class SdeBuildStatusResolverTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testResolvesTheStatusOfTheImportedBuildAgainstTheLatestCcpBuild(?int $importedBuildNumber, ?int $latestBuildNumber, SdeBuildStatus $expected): void
    {
        $state = (new SdeBuildState())
            ->setImportedBuildNumber($importedBuildNumber)
            ->setLatestBuildNumber($latestBuildNumber);

        self::assertSame($expected, (new SdeBuildStatusResolver())->resolve($state));
    }

    public static function statuses(): iterable
    {
        yield 'imported build older than the latest' => [3213160, 3693310, SdeBuildStatus::Outdated];
        yield 'imported build one behind the latest' => [3693309, 3693310, SdeBuildStatus::Outdated];
        yield 'no imported build recorded' => [null, 3693310, SdeBuildStatus::NotRecorded];
        yield 'imported build equal to the latest' => [3693310, 3693310, SdeBuildStatus::UpToDate];
        yield 'imported build newer than the latest' => [3700000, 3693310, SdeBuildStatus::UpToDate];
        yield 'never checked with an imported build' => [3693310, null, SdeBuildStatus::Unknown];
        yield 'never checked and nothing imported' => [null, null, SdeBuildStatus::Unknown];
    }

    public function testStatusValuesAreTheOnesExposedByTheApi(): void
    {
        self::assertSame(
            ['unknown', 'not_recorded', 'up_to_date', 'outdated'],
            array_map(static fn (SdeBuildStatus $status): string => $status->value, SdeBuildStatus::cases()),
        );
    }
}
