<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
#[Group('issue-1315')]
final class IndustryProjectSurplusStoredTest extends TestCase
{
    public function testNewProjectHasNoStoredSurplus(): void
    {
        $project = new IndustryProject();

        self::assertNull($project->getSurplusStoredAt());
        self::assertNull($project->getSurplusStoredBy());
    }

    public function testMarkingTheSurplusStoredKeepsWhenAndByWhom(): void
    {
        $storer = new User();
        $storedAt = new \DateTimeImmutable('2026-11-22T09:30:00+00:00');

        $project = (new IndustryProject())->markSurplusStored($storer, $storedAt);

        self::assertSame($storedAt, $project->getSurplusStoredAt());
        self::assertSame($storer, $project->getSurplusStoredBy());
    }
}
