<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningSite;

use App\Service\MiningSite\SiteTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteTypeResolver::class)]
#[Group('issue-309')]
final class SiteTypeResolverTest extends TestCase
{
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;

    public function testTheTypeWithTheMostUnitsIsTheTypeOfTheSite(): void
    {
        $types = [
            ['typeId' => self::VELDSPAR, 'status' => 'present', 'units' => 30_000],
            ['typeId' => self::SCORDITE, 'status' => 'present', 'units' => 45_000],
        ];

        self::assertSame(self::SCORDITE, (new SiteTypeResolver())->dominantTypeIdOf($types));
    }

    public function testEqualUnitsGiveTheSmallestTypeId(): void
    {
        $types = [
            ['typeId' => self::VELDSPAR, 'status' => 'present', 'units' => 30_000],
            ['typeId' => self::SCORDITE, 'status' => 'present', 'units' => 30_000],
        ];

        self::assertSame(self::SCORDITE, (new SiteTypeResolver())->dominantTypeIdOf($types));
    }

    public function testScanWithoutAnyTypeHasNoDominantType(): void
    {
        self::assertNull((new SiteTypeResolver())->dominantTypeIdOf([]));
    }
}
