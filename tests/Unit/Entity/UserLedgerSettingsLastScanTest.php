<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\UserLedgerSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserLedgerSettings::class)]
#[Group('issue-1079')]
final class UserLedgerSettingsLastScanTest extends TestCase
{
    public function testNoScanHasRunOnANewSettings(): void
    {
        $settings = new UserLedgerSettings();

        $this->assertNull($settings->getLastLootScanAt());
        $this->assertNull($settings->getLastExpenseScanAt());
    }

    public function testTheLootScanAndTheExpenseScanAreRecordedIndependently(): void
    {
        $lootScan = new \DateTimeImmutable('2026-11-18 09:00:00');
        $expenseScan = new \DateTimeImmutable('2026-11-12 21:30:00');
        $settings = new UserLedgerSettings();

        $settings->setLastLootScanAt($lootScan);
        $settings->setLastExpenseScanAt($expenseScan);

        $this->assertSame($lootScan, $settings->getLastLootScanAt());
        $this->assertSame($expenseScan, $settings->getLastExpenseScanAt());
    }
}
