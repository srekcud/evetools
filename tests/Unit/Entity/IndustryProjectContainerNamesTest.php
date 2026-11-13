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
