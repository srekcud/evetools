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
#[Group('issue-894')]
final class IndustryProjectContainerNamesTest extends TestCase
{
    public function testNewProjectWatchesNoContainer(): void
    {
        self::assertSame([], (new IndustryProject())->getContainerNames());
    }

    public function testNamesAreKeptInTheGivenOrder(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Products', 'Mats', 'Fuel']);

        self::assertSame(['Products', 'Mats', 'Fuel'], $project->getContainerNames());
    }

    public function testNamesAreTrimmedAndBlankOnesDropped(): void
    {
        $project = (new IndustryProject())->setContainerNames(['  Mats ', '', "\t", 'Products']);

        self::assertSame(['Mats', 'Products'], $project->getContainerNames());
    }

    public function testNamesDifferingOnlyByCaseOrEdgeSpacesAreMergedKeepingTheFirst(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Mats', ' MATS', 'mats ', 'Products']);

        self::assertSame(['Mats', 'Products'], $project->getContainerNames());
    }

    public function testCaseMergingHandlesNonAsciiLetters(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Éclair', 'éCLAIR']);

        self::assertSame(['Éclair'], $project->getContainerNames());
    }

    public function testReplacingTheNamesKeepsTheRowsOfTheNamesThatStay(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Mats', 'Products']);
        $rowsBefore = $project->getContainers()->toArray();

        $project->setContainerNames(['Products', 'Fuel']);

        self::assertSame(['Products', 'Fuel'], $project->getContainerNames());
        self::assertSame($rowsBefore[1], $project->getContainers()->first());
        self::assertCount(2, $project->getContainers());
    }

    public function testTheSameNameAtSeveralStationsIsListedOnce(): void
    {
        $project = new IndustryProject();
        $project->addContainerAt('Mats', 60_003_760);
        $project->addContainerAt('mats', 60_008_494);
        $project->addContainerAt('Fuel', 60_011_866);

        self::assertSame(['Mats', 'Fuel'], $project->getContainerNames());
    }

    public function testReplacingTheNamesKeepsEveryRowOfAnAlreadyLocatedName(): void
    {
        $project = new IndustryProject();
        $project->addContainerAt('Mats', 60_003_760);
        $project->addContainerAt('Mats', 60_008_494);

        $project->setContainerNames(['Mats']);

        self::assertSame([60_003_760, 60_008_494], array_map(static fn (IndustryProjectContainer $container): ?int => $container->getLocationId(), $project->getContainers()->toArray()));
    }

    public function testEmptyListClearsTheNames(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Mats']);

        $project->setContainerNames([]);

        self::assertSame([], $project->getContainerNames());
    }

    public function testEachRowKnowsItsProjectAndItsName(): void
    {
        $project = (new IndustryProject())->setContainerNames(['Mats']);

        $row = $project->getContainers()->first();

        self::assertSame([$project, 'Mats'], [$row->getProject(), $row->getName()]);
    }
}
