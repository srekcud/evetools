<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
#[CoversClass(IndustryProjectContainer::class)]
#[Group('issue-1292')]
final class IndustryProjectContainerLocationTest extends TestCase
{
    private const int ISHTAR_STATION_LOCATION_ID = 60_003_760;

    public function testNewContainerHasNoLocation(): void
    {
        $container = new IndustryProjectContainer(new IndustryProject(), 'Mats');

        self::assertNull($container->getLocationId());
    }

    public function testLocateAtStoresTheLocationAndReturnsTheSameContainer(): void
    {
        $container = new IndustryProjectContainer(new IndustryProject(), 'Mats');

        $returned = $container->locateAt(self::ISHTAR_STATION_LOCATION_ID);

        self::assertSame($container, $returned);
        self::assertSame(self::ISHTAR_STATION_LOCATION_ID, $container->getLocationId());
    }

    public function testSetContainerNamesKeepsTheLocationOfAContainerWhoseNameStays(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Mats', 'Fuel']);
        $project->getContainers()->first()->locateAt(self::ISHTAR_STATION_LOCATION_ID);
        $matsContainer = $project->getContainers()->first();

        $project->setContainerNames(['Mats']);

        self::assertCount(1, $project->getContainers());
        self::assertSame($matsContainer, $project->getContainers()->first());
        self::assertSame(self::ISHTAR_STATION_LOCATION_ID, $project->getContainers()->first()->getLocationId());
    }

    public function testSetContainerNamesLosesTheLocationOfAContainerWhoseNameDisappears(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Mats']);
        $project->getContainers()->first()->locateAt(self::ISHTAR_STATION_LOCATION_ID);

        $project->setContainerNames(['Fuel']);
        $project->setContainerNames(['Mats']);

        self::assertCount(1, $project->getContainers());
        self::assertNull($project->getContainers()->first()->getLocationId());
    }
}
