<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\TimeEfficiency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnedBlueprintUse::class)]
#[Group('issue-411')]
final class OwnedBlueprintUseTest extends TestCase
{
    public function testHigherMaterialEfficiencyWinsWhateverTheKindOrTheTimeEfficiency(): void
    {
        $copy = self::use(8, 0, false);
        $original = self::use(5, 20, true);

        self::assertTrue($copy->isBetterThan($original));
        self::assertFalse($original->isBetterThan($copy));
    }

    public function testHigherTimeEfficiencyWinsAtEqualMaterialEfficiency(): void
    {
        self::assertTrue(self::use(8, 16, false)->isBetterThan(self::use(8, 12, true)));
        self::assertFalse(self::use(8, 12, true)->isBetterThan(self::use(8, 16, false)));
    }

    public function testOriginalBeatsCopyAtEqualEfficiencies(): void
    {
        self::assertTrue(self::use(8, 16, true)->isBetterThan(self::use(8, 16, false)));
        self::assertFalse(self::use(8, 16, false)->isBetterThan(self::use(8, 16, true)));
    }

    public function testIdenticalUsesAreNotBetterThanEachOther(): void
    {
        self::assertFalse(self::use(8, 16, true)->isBetterThan(self::use(8, 16, true)));
    }

    private static function use(int $materialEfficiency, int $timeEfficiency, bool $original): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency)), $original);
    }
}
