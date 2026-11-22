<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectContainer::class)]
#[Group('issue-1320')]
final class IndustryProjectContainerClearLocationTest extends TestCase
{
    private const int FORGE_STATION_LOCATION_ID = 60_003_760;

    public function testClearLocationRemovesTheLocationAndReturnsTheSameContainer(): void
    {
        $container = (new IndustryProjectContainer(new IndustryProject(), 'Mats'))->locateAt(self::FORGE_STATION_LOCATION_ID);

        $returned = $container->clearLocation();

        self::assertSame($container, $returned);
        self::assertNull($container->getLocationId());
    }

    public function testClearLocationKeepsTheName(): void
    {
        $container = (new IndustryProjectContainer(new IndustryProject(), 'Mats'))->locateAt(self::FORGE_STATION_LOCATION_ID);

        $container->clearLocation();

        self::assertSame('Mats', $container->getName());
    }

    public function testClearLocationOnAContainerWithoutLocationLeavesItWithout(): void
    {
        $container = new IndustryProjectContainer(new IndustryProject(), 'Mats');

        $container->clearLocation();

        self::assertNull($container->getLocationId());
    }

    public function testAContainerCanBeLocatedAgainAfterItsLocationWasCleared(): void
    {
        $container = (new IndustryProjectContainer(new IndustryProject(), 'Mats'))->locateAt(self::FORGE_STATION_LOCATION_ID);

        $container->clearLocation()->locateAt(60_008_494);

        self::assertSame(60_008_494, $container->getLocationId());
    }
}
