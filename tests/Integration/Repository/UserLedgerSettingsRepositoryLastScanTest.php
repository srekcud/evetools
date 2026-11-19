<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\UserLedgerSettings;
use App\Repository\UserLedgerSettingsRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1079')]
final class UserLedgerSettingsRepositoryLastScanTest extends IntegrationTestCase
{
    private UserLedgerSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserLedgerSettingsRepository::class);
    }

    public function testTheLootScanIsStoredWithoutTouchingTheExpenseScan(): void
    {
        $user = $this->createUser();
        $this->flushAndClear();
        $scannedAt = new \DateTimeImmutable('2026-11-18 09:00:00');

        $this->repository->recordLootScan($this->em->find($user::class, $user->getId()), $scannedAt);
        $this->em->clear();

        $settings = $this->em->getRepository(UserLedgerSettings::class)->findOneBy([]);
        self::assertSame('2026-11-18 09:00:00', $settings?->getLastLootScanAt()?->format('Y-m-d H:i:s'));
        self::assertNull($settings->getLastExpenseScanAt());
    }

    public function testTheExpenseScanIsStoredWithoutTouchingTheLootScan(): void
    {
        $user = $this->createUser();
        $this->flushAndClear();
        $scannedAt = new \DateTimeImmutable('2026-11-17 22:15:00');

        $this->repository->recordExpenseScan($this->em->find($user::class, $user->getId()), $scannedAt);
        $this->em->clear();

        $settings = $this->em->getRepository(UserLedgerSettings::class)->findOneBy([]);
        self::assertSame('2026-11-17 22:15:00', $settings?->getLastExpenseScanAt()?->format('Y-m-d H:i:s'));
        self::assertNull($settings->getLastLootScanAt());
    }
}
