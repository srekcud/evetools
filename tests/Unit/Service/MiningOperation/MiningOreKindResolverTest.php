<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Enum\MiningCompressorKind;
use App\Service\MiningOperation\MiningOreKindResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOreKindResolver::class)]
#[Group('issue-311')]
final class MiningOreKindResolverTest extends TestCase
{
    private const int ASTEROID_CATEGORY = 25;
    private const int CELESTIAL_CATEGORY = 2;
    private const int SPODUMAIN_GROUP = 461;
    private const int ICE_GROUP = 465;
    private const int MERCOXIT_GROUP = 468;
    private const int HARVESTABLE_CLOUD_GROUP = 711;
    private const int UBIQUITOUS_MOON_GROUP = 1884;
    private const int EXCEPTIONAL_MOON_GROUP = 1923;

    private MiningOreKindResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MiningOreKindResolver();
    }

    public function testIceGroupIsTheIceKind(): void
    {
        self::assertSame(MiningCompressorKind::Ice, $this->resolver->kindOf(self::ASTEROID_CATEGORY, self::ICE_GROUP));
    }

    public function testMercoxitGroupIsTheMercoxitKind(): void
    {
        self::assertSame(MiningCompressorKind::Mercoxit, $this->resolver->kindOf(self::ASTEROID_CATEGORY, self::MERCOXIT_GROUP));
    }

    public function testEveryMoonOreGroupIsTheMoonKind(): void
    {
        self::assertSame(
            [MiningCompressorKind::Moon, MiningCompressorKind::Moon, MiningCompressorKind::Moon, MiningCompressorKind::Moon, MiningCompressorKind::Moon],
            array_map(fn (int $groupId): ?MiningCompressorKind => $this->resolver->kindOf(self::ASTEROID_CATEGORY, $groupId), [self::UBIQUITOUS_MOON_GROUP, 1920, 1921, 1922, self::EXCEPTIONAL_MOON_GROUP]),
        );
    }

    public function testHarvestableCloudGroupIsTheGasKind(): void
    {
        self::assertSame(MiningCompressorKind::Gas, $this->resolver->kindOf(self::CELESTIAL_CATEGORY, self::HARVESTABLE_CLOUD_GROUP));
    }

    public function testAnyOtherGroupOfTheAsteroidCategoryIsTheAsteroidKind(): void
    {
        self::assertSame(MiningCompressorKind::Asteroid, $this->resolver->kindOf(self::ASTEROID_CATEGORY, self::SPODUMAIN_GROUP));
    }

    public function testTypeWithoutCategoryOrOutsideTheAsteroidCategoryHasNoKind(): void
    {
        self::assertSame(
            [null, null, null],
            [
                $this->resolver->kindOf(null, self::SPODUMAIN_GROUP),
                $this->resolver->kindOf(4, self::SPODUMAIN_GROUP),
                $this->resolver->kindOf(null, null),
            ],
        );
    }
}
