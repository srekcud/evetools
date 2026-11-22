<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryStructureConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryStructureConfig::class)]
#[Group('issue-1343')]
final class IndustryStructureConfigHullToSpecifyTest extends TestCase
{
    #[DataProvider('legacyStructureTypeProvider')]
    public function testLegacyStructureTypeIsAHullToSpecify(string $structureType): void
    {
        $this->assertTrue((new IndustryStructureConfig())->setStructureType($structureType)->isHullToSpecify());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyStructureTypeProvider(): iterable
    {
        yield 'engineering_complex' => ['engineering_complex'];
        yield 'refinery' => ['refinery'];
    }

    #[DataProvider('knownHullProvider')]
    public function testKnownHullAndNpcStationAreNotAHullToSpecify(string $structureType): void
    {
        $this->assertFalse((new IndustryStructureConfig())->setStructureType($structureType)->isHullToSpecify());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function knownHullProvider(): iterable
    {
        yield 'station' => ['station'];
        yield 'raitaru' => ['raitaru'];
        yield 'azbel' => ['azbel'];
        yield 'sotiyo' => ['sotiyo'];
        yield 'athanor' => ['athanor'];
        yield 'tatara' => ['tatara'];
    }

    public function testEachLegacyTypeOffersItsOwnHullsOnly(): void
    {
        $this->assertSame(
            ['engineering_complex' => ['raitaru', 'azbel', 'sotiyo'], 'refinery' => ['athanor', 'tatara']],
            IndustryStructureConfig::HULLS_OF_LEGACY_TYPE,
        );
    }
}
