<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
