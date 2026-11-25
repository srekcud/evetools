<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Dto\PlanetaryTaxDay;
use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1399')]
final class CachedWalletJournalEntryPlanetaryTaxesTest extends IntegrationTestCase
{
    private const int PLANET_A = 40_000_001;
    private const int PLANET_B = 40_000_002;
    private const string SINCE = '2026-11-01 00:00:00';

    private CachedWalletJournalEntryRepository $repository;
    private int $nextEntryId = 9_000_000_001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedWalletJournalEntryRepository::class);
    }

    public function testSumsTheImportAndExportTaxesPerCharacterPlanetAndDayAsPositiveIskPaid(): void
    {
        $main = $this->createCharacter(null, 'Aria Solberg');
        $this->persistLine($main, 'planetary_import_tax', -5_000.0, self::PLANET_A, '2026-11-20 03:10:00');
        $this->persistLine($main, 'planetary_import_tax', -7_000.0, self::PLANET_A, '2026-11-20 21:45:00');
        $this->persistLine($main, 'planetary_export_tax', -3_000.0, self::PLANET_A, '2026-11-20 21:46:00');
        $this->persistLine($main, 'planetary_export_tax', -900.0, self::PLANET_B, '2026-11-21 00:05:00');
        $this->em->flush();
        $this->em->clear();

        $rows = $this->repository->planetaryTaxesByColonyAndDay([$this->reload($main)], new \DateTimeImmutable(self::SINCE));

        self::assertEquals(
            [
                new PlanetaryTaxDay($this->reload($main)->getEveCharacterId(), self::PLANET_A, '2026-11-20', 12_000.0, 3_000.0),
                new PlanetaryTaxDay($this->reload($main)->getEveCharacterId(), self::PLANET_B, '2026-11-21', 0.0, 900.0),
            ],
            $rows,
        );
    }

    public function testIgnoresOtherCharactersOtherRefTypesLinesWithoutPlanetAndOlderLines(): void
    {
        $main = $this->createCharacter(null, 'Aria Solberg');
        $stranger = $this->createCharacter(null, 'Corin Vale');
        $this->persistLine($main, 'planetary_import_tax', -1_000.0, self::PLANET_A, '2026-11-20 10:00:00');
        $this->persistLine($stranger, 'planetary_import_tax', -9_999.0, self::PLANET_A, '2026-11-20 10:00:00');
        $this->persistLine($main, 'contract_brokers_fee', -30_000.0, self::PLANET_A, '2026-11-20 10:00:00');
        $this->persistLine($main, 'planetary_export_tax', -8_888.0, null, '2026-11-20 10:00:00');
        $this->persistLine($main, 'planetary_import_tax', -7_777.0, self::PLANET_A, '2026-10-31 23:59:59');
        $this->em->flush();
        $this->em->clear();

        $rows = $this->repository->planetaryTaxesByColonyAndDay([$this->reload($main)], new \DateTimeImmutable(self::SINCE));

        self::assertSame([[self::PLANET_A, '2026-11-20', 1_000.0, 0.0]], array_map(static fn (PlanetaryTaxDay $row): array => [$row->planetId, $row->day, $row->importTax, $row->exportTax], $rows));
    }

    public function testNoCharacterGivesNoRows(): void
    {
        self::assertSame([], $this->repository->planetaryTaxesByColonyAndDay([], new \DateTimeImmutable(self::SINCE)));
    }

    private function persistLine(Character $character, string $refType, float $amount, ?int $planetId, string $date): void
    {
        $this->em->persist(
            (new CachedWalletJournalEntry())
                ->setCharacter($character)
                ->setJournalEntryId($this->nextEntryId++)
                ->setRefType($refType)
                ->setAmount($amount)
                ->setDate(new \DateTimeImmutable($date))
                ->setContextId($planetId)
                ->setContextIdType($planetId === null ? null : 'planet_id'),
        );
    }

    private function reload(Character $character): Character
    {
        return $this->em->find(Character::class, $character->getId());
    }
}
