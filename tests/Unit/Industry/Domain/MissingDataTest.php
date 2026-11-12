<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingData::class)]
final class MissingDataTest extends TestCase
{
    public function testItemVolumeNamesTheTypeWhoseVolumeIsAbsent(): void
    {
        $missingData = MissingData::itemVolume(42);

        $this->assertSame('item_volume', $missingData->reason);
        $this->assertSame(42, $missingData->typeId);
    }

    public function testJumpRouteBelongsToNoType(): void
    {
        $missingData = MissingData::jumpRoute();

        $this->assertSame('jump_route', $missingData->reason);
        $this->assertNull($missingData->typeId);
    }

    public function testCopyBaseTimeNamesTheProductWhoseCopyTimeIsAbsent(): void
    {
        $missingData = MissingData::copyBaseTime(638);

        $this->assertSame(['copy_base_time', 638], [$missingData->reason, $missingData->typeId]);
    }

    public function testCopyReasonsOfThePlannedCopyBelongToNoType(): void
    {
        $reasons = array_map(
            static fn (MissingData $missingData): array => [$missingData->reason, $missingData->typeId],
            [MissingData::copySkills(), MissingData::copyStructureNotPlanned(), MissingData::noCopyStructure(), MissingData::plannedStructureDeleted()],
        );

        $this->assertSame([['copy_skills', null], ['copy_structure_not_planned', null], ['no_copy_structure', null], ['planned_structure_deleted', null]], $reasons);
    }

    #[Group('issue-780')]
    public function testFacilityTaxBelongsToNoType(): void
    {
        $missingData = MissingData::facilityTax();

        $this->assertSame(['facility_tax', null], [$missingData->reason, $missingData->typeId]);
    }
}
