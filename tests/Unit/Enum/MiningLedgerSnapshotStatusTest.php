<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MiningLedgerSnapshotStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningLedgerSnapshotStatus::class)]
#[Group('issue-263')]
final class MiningLedgerSnapshotStatusTest extends TestCase
{
    public function testASnapshotIsSettledOnceTakenOrDefinitelyUnreadable(): void
    {
        $settledByStatus = [];
        foreach (MiningLedgerSnapshotStatus::cases() as $status) {
            $settledByStatus[$status->value] = $status->isSettled();
        }

        self::assertSame(
            [
                'scheduling' => false,
                'waiting' => false,
                'taken' => true,
                'reconnect_required' => true,
                'failed' => true,
                'out_of_window' => true,
            ],
            $settledByStatus,
        );
    }
}
