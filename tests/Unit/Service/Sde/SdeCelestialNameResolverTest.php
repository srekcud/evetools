<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sde;

use App\Entity\Sde\MapMoon;
use App\Entity\Sde\MapPlanet;
use App\Entity\Sde\MapSolarSystem;
use App\Repository\Sde\MapMoonRepository;
use App\Repository\Sde\MapPlanetRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Sde\ResolvedMoon;
use App\Service\Sde\SdeCelestialNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeCelestialNameResolver::class)]
#[Group('issue-849')]
final class SdeCelestialNameResolverTest extends TestCase
{
    private const int ABHAN_ID = 30_005_254;
    private const int ABHAN_V_ID = 40_332_310;
    private const int ABHAN_V_MOON_8_ID = 40_332_314;
    private const int ORPHAN_SYSTEM_ID = 39_999_999;

    public function testAPlanetKnownToTheSdeIsNamedAfterItsSolarSystem(): void
    {
        self::assertSame('Abhan V', $this->resolver()->planetName(self::ABHAN_V_ID));
    }

    public function testAPlanetMissingFromTheSdeHasNoName(): void
    {
        self::assertNull($this->resolver()->planetName(40_000_000));
    }

    public function testAPlanetWhoseSolarSystemIsMissingFromTheSdeHasNoName(): void
    {
        self::assertNull($this->resolver(planetSystemId: self::ORPHAN_SYSTEM_ID)->planetName(self::ABHAN_V_ID));
    }

    public function testAMoonKnownToTheSdeIsResolvedWithItsNameAndItsSolarSystem(): void
    {
        $moon = $this->resolver()->moon(self::ABHAN_V_MOON_8_ID);

        self::assertEquals(new ResolvedMoon('Abhan V - Moon 8', self::ABHAN_ID), $moon);
    }

    public function testAMoonMissingFromTheSdeIsNotResolved(): void
    {
        self::assertNull($this->resolver()->moon(40_000_001));
    }

    public function testAMoonWhoseSolarSystemIsMissingFromTheSdeIsNotResolved(): void
    {
        self::assertNull($this->resolver(moonSystemId: self::ORPHAN_SYSTEM_ID)->moon(self::ABHAN_V_MOON_8_ID));
    }

    private function resolver(int $planetSystemId = self::ABHAN_ID, int $moonSystemId = self::ABHAN_ID): SdeCelestialNameResolver
    {
        $planet = (new MapPlanet())->setPlanetId(self::ABHAN_V_ID)->setSolarSystemId($planetSystemId)->setCelestialIndex(5)->setTypeId(11);
        $moon = (new MapMoon())->setMoonId(self::ABHAN_V_MOON_8_ID)->setSolarSystemId($moonSystemId)->setOrbitId(self::ABHAN_V_ID)->setCelestialIndex(5)->setOrbitIndex(8);

        $planets = $this->createStub(MapPlanetRepository::class);
        $planets->method('find')->willReturnCallback(static fn (mixed $id): ?MapPlanet => $id === self::ABHAN_V_ID ? $planet : null);
        $moons = $this->createStub(MapMoonRepository::class);
        $moons->method('find')->willReturnCallback(static fn (mixed $id): ?MapMoon => $id === self::ABHAN_V_MOON_8_ID ? $moon : null);
        $systems = $this->createStub(MapSolarSystemRepository::class);
        $systems->method('findBySolarSystemId')->willReturnCallback(
            static fn (int $id): ?MapSolarSystem => $id === self::ABHAN_ID ? (new MapSolarSystem())->setSolarSystemId($id)->setSolarSystemName('Abhan') : null,
        );

        return new SdeCelestialNameResolver($planets, $moons, $systems);
    }
}
