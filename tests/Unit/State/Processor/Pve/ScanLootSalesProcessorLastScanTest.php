<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\User;
use App\Repository\UserLedgerSettingsRepository;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-1079')]
final class ScanLootSalesProcessorLastScanTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    public function testTheScanRecordsTheMomentTheLootScanRan(): void
    {
        $ledgerSettingsRepository = $this->createMock(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->expects($this->once())
            ->method('recordLootScan')
            ->with($this->isInstanceOf(User::class), $this->callback(static fn (\DateTimeImmutable $scannedAt): bool => abs($scannedAt->getTimestamp() - time()) < 5));
        $ledgerSettingsRepository->expects($this->never())->method('recordExpenseScan');

        $this->createProcessor([], characters: [], ledgerSettingsRepository: $ledgerSettingsRepository)->process(null, new Post());
    }

    public function testTheScanRecordsNothingWhenNoLootTypeIsConfiguredBecauseNothingWasRead(): void
    {
        $ledgerSettingsRepository = $this->createMock(UserLedgerSettingsRepository::class);
        $ledgerSettingsRepository->expects($this->never())->method('recordLootScan');
        $ledgerSettingsRepository->expects($this->never())->method('recordExpenseScan');

        $this->createProcessor([], characters: [], lootTypeIds: [], ledgerSettingsRepository: $ledgerSettingsRepository)->process(null, new Post());
    }
}
