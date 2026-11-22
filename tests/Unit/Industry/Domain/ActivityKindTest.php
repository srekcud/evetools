<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivityKind::class)]
final class ActivityKindTest extends TestCase
{
    public function testCopyingIsALaboratoryActivity(): void
    {
        self::assertTrue(ActivityKind::Copying->isLaboratory());
    }

    public function testInventionIsALaboratoryActivity(): void
    {
        self::assertTrue(ActivityKind::Invention->isLaboratory());
    }

    public function testMaterialEfficiencyResearchIsALaboratoryActivity(): void
    {
        self::assertTrue(ActivityKind::ResearchMaterial->isLaboratory());
    }

    public function testTimeEfficiencyResearchIsALaboratoryActivity(): void
    {
        self::assertTrue(ActivityKind::ResearchTime->isLaboratory());
    }

    public function testManufacturingIsNotALaboratoryActivity(): void
    {
        self::assertFalse(ActivityKind::Manufacturing->isLaboratory());
    }

    public function testReactionIsNotALaboratoryActivity(): void
    {
        self::assertFalse(ActivityKind::Reaction->isLaboratory());
    }
}
