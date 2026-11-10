<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\ScienceRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScienceRig::class)]
#[Group('issue-523')]
final class ScienceRigTest extends TestCase
{
    public function testCopyRigKeepsItsTimeBonusAndItsAbsentCostBonus(): void
    {
        $timeBonus = new RigBonus(24.0, new RigSecurityModifiers(1.0, 1.9, 2.1));

        $scienceRig = new ScienceRig(ActivityKind::Copying, $timeBonus, null);

        $this->assertSame(ActivityKind::Copying, $scienceRig->activity);
        $this->assertSame($timeBonus, $scienceRig->timeBonus);
        $this->assertNull($scienceRig->costBonus);
    }

    public function testManufacturingIsRefusedAsTheActivityOfAScienceRig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScienceRig(ActivityKind::Manufacturing, new RigBonus(20.0, new RigSecurityModifiers(1.0, 1.9, 2.1)), null);
    }

    public function testReactionIsRefusedAsTheActivityOfAScienceRig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScienceRig(ActivityKind::Reaction, null, new RigBonus(10.0, new RigSecurityModifiers(null, 1.0, 1.1)));
    }

    public function testScienceRigWithoutTimeNorCostBonusIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScienceRig(ActivityKind::Invention, null, null);
    }
}
