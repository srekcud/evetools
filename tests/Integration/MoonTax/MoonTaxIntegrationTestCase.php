<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Entity\Character;
use App\Entity\CorporationWalletDonation;
use App\Entity\MoonObserverRecord;
use App\Entity\MoonTaxStatement;
use App\Entity\MoonTaxSyncState;
use App\Service\Sync\MoonTaxSyncService;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class MoonTaxIntegrationTestCase extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use SeedsMoonTax;
    use StubsMoonTaxEsi;

    protected const string NOW = '2026-11-10T12:00:00+00:00';
    protected const string ISO_FORMAT = \DateTimeInterface::ATOM;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        parent::setUp();
        $this->replaceEsi();
        $this->replaceMercure();
        $this->replaceMoonOreJitaMarket();
        $this->seedMoonOreSde();
        $this->persistSetting(self::ROCK_HOLLOW_ID);
    }

    protected function ilyra(array $roles = ['Accountant'], array $scopes = self::ALL_SCOPES): Character
    {
        return $this->persistCharacterOfCorporation('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, $roles, $scopes);
    }

    protected function aurel(array $roles = ['Accountant'], array $scopes = self::ALL_SCOPES): Character
    {
        return $this->persistCharacterOfCorporation('Aurel Kade', self::AUREL_ID, self::CORPORATION, $roles, $scopes);
    }

    protected function runSync(int $corporationId = self::CORPORATION): MoonTaxSyncState
    {
        $this->em->flush();

        return self::getContainer()->get(MoonTaxSyncService::class)->sync($corporationId);
    }

    protected function runClosure(int $corporationId = self::CORPORATION): void
    {
        $this->em->flush();
        self::getContainer()->get(\App\Service\MoonTax\MoonTaxStatementCloser::class)->close($corporationId);
        $this->em->clear();
    }

    protected function storedRecords(): array
    {
        $this->em->clear();
        $rows = array_map(
            static fn (MoonObserverRecord $record): array => [$record->getObserverId(), $record->getCharacterId(), $record->getTypeId(), $record->getLastUpdated()->format('Y-m-d'), $record->getQuantity(), $record->getRecordedCorporationId()],
            $this->em->getRepository(MoonObserverRecord::class)->findAll(),
        );
        sort($rows);

        return $rows;
    }

    protected function storedDonations(): array
    {
        $this->em->clear();
        $rows = array_map(
            static fn (CorporationWalletDonation $donation): array => [$donation->getJournalId(), $donation->getFirstPartyId(), $donation->getAmount(), $donation->getDate()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT), $donation->getReason(), $donation->getAttributedCharacterId()],
            $this->em->getRepository(CorporationWalletDonation::class)->findAll(),
        );
        sort($rows);

        return $rows;
    }

    protected function storedStatements(): array
    {
        $this->em->clear();
        $statements = $this->em->getRepository(MoonTaxStatement::class)->findAll();
        usort($statements, static fn (MoonTaxStatement $left, MoonTaxStatement $right): int => [$left->getPeriodStart(), $left->getCharacterId(), $left->getStructureId()] <=> [$right->getPeriodStart(), $right->getCharacterId(), $right->getStructureId()]);

        return $statements;
    }

    protected function syncStateSummary(int $corporationId = self::CORPORATION): array
    {
        $this->em->clear();
        $state = $this->em->getRepository(MoonTaxSyncState::class)->findOneBy(['corporationId' => $corporationId]);
        self::assertNotNull($state, sprintf('No sync state stored for corporation %d', $corporationId));

        return [
            'observersSyncedAt' => $state->getObserversSyncedAt()?->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT),
            'walletSyncedAt' => $state->getWalletSyncedAt()?->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT),
            'observersFailure' => $state->getObserversFailure()?->value,
            'walletFailure' => $state->getWalletFailure()?->value,
        ];
    }

    protected static function at(string $moment): string
    {
        return (new \DateTimeImmutable($moment, new \DateTimeZone('UTC')))->format(self::ISO_FORMAT);
    }
}
