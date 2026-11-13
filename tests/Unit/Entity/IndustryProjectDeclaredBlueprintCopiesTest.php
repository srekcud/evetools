<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectDeclaredBlueprintCopy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
#[CoversClass(IndustryProjectDeclaredBlueprintCopy::class)]
#[Group('issue-804')]
final class IndustryProjectDeclaredBlueprintCopiesTest extends TestCase
{
    private const int ISHTAR = 12_005;
    private const int HAIL_L = 12_779;
    private const int HOBGOBLIN_II = 2_455;

    public function testProjectWithoutDeclarationReportsNoDeclaredRuns(): void
    {
        $project = new IndustryProject();

        self::assertSame([], $project->declaredRunsByProductTypeId());
        self::assertCount(0, $project->getDeclaredBlueprintCopies());
    }

    public function testDeclaredRunsAreReportedByProductTypeId(): void
    {
        $project = (new IndustryProject())->replaceDeclaredBlueprintCopies([
            new IndustryProjectDeclaredBlueprintCopy(self::ISHTAR, 13),
            new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 6),
        ]);

        self::assertSame([self::ISHTAR => 13, self::HAIL_L => 6], $project->declaredRunsByProductTypeId());
    }

    public function testReplacingTheDeclarationsDropsThePreviousOnesAndLinksTheNewOnesToTheProject(): void
    {
        $project = (new IndustryProject())->replaceDeclaredBlueprintCopies([
            new IndustryProjectDeclaredBlueprintCopy(self::ISHTAR, 13),
            new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 6),
        ]);
        $hobgoblin = new IndustryProjectDeclaredBlueprintCopy(self::HOBGOBLIN_II, 9);

        $project->replaceDeclaredBlueprintCopies([$hobgoblin]);

        self::assertSame([self::HOBGOBLIN_II => 9], $project->declaredRunsByProductTypeId());
        self::assertSame([$hobgoblin], $project->getDeclaredBlueprintCopies()->getValues());
        self::assertSame($project, $hobgoblin->getProject());
    }

    public function testReplacingWithAnEmptyListRemovesEveryDeclaration(): void
    {
        $project = (new IndustryProject())->replaceDeclaredBlueprintCopies([new IndustryProjectDeclaredBlueprintCopy(self::ISHTAR, 13)]);

        $project->replaceDeclaredBlueprintCopies([]);

        self::assertSame([], $project->declaredRunsByProductTypeId());
        self::assertCount(0, $project->getDeclaredBlueprintCopies());
    }
}
