<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Service\MiningSite\MiningSiteScanRecorder;
use App\Service\MiningSite\ScanLine;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MiningSiteScanRecorder::class)]
#[Group('issue-306')]
final class MiningSiteSharedScansTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsMiningSiteMarket;

    private const array VELDSPAR_SERIES = ['20:00:00' => 30_000, '20:10:00' => 25_000, '20:20:00' => 18_000];

    private MiningSiteScanRecorder $recorder;
    private MiningSiteResourceMapper $mapper;
    private User $aria;
    private User $brann;
    private User $cyra;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'));
        parent::setUp();
        $this->replaceJitaBuyMarket();
        $this->seedMiningSiteSde($this->em);
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C1);
        $this->em->flush();
        $this->recorder = self::getContainer()->get(MiningSiteScanRecorder::class);
        $this->mapper = self::getContainer()->get(MiningSiteResourceMapper::class);
    }

    public function testEachScanStoresTheParticipantWhoRecordedItAndNotTheOwnerOfTheSite(): void
    {
        $site = $this->persistSite('Belt Aldranette');

        $this->recordSeries($site, [$this->aria, $this->brann, $this->cyra]);

        self::assertSame(['Aria Solberg', 'Brann Holt', 'Cyra Lune'], $this->authorNamesOf($site));
    }

    public function testScansOfThreeAuthorsGiveTheSameEstimateAsTheSameScansOfASingleAuthor(): void
    {
        $sharedSite = $this->persistSite('Belt Aldranette');
        $soloSite = $this->persistSite('Belt Jaspet');

        $this->recordSeries($sharedSite, [$this->aria, $this->brann, $this->cyra]);
        $this->recordSeries($soloSite, [$this->aria, $this->aria, $this->aria]);

        $estimate = [[null, null], ['21:00:00', '21:00:00'], ['20:45:43', '20:50:33']];
        self::assertSame(
            [
                ['Aria Solberg', 'Brann Holt', 'Cyra Lune'],
                ['Aria Solberg', 'Aria Solberg', 'Aria Solberg'],
                $estimate,
                $estimate,
            ],
            [$this->authorNamesOf($sharedSite), $this->authorNamesOf($soloSite), $this->siteEtasOf($sharedSite), $this->siteEtasOf($soloSite)],
        );
    }

    public function testScanNumberCountsTheScansOfTheSiteFromOneAndTypeCountLeavesOutTheDepletedTypes(): void
    {
        $site = $this->persistSite('Belt Aldranette');
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'));
        $first = $this->recorder->record($site, [new ScanLine(self::VELDSPAR, 30_000), new ScanLine(self::SCORDITE, 8_000)], $this->aria);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:10:00+00:00'));
        $second = $this->recorder->record($site, [new ScanLine(self::VELDSPAR, 25_000)], $this->brann);

        self::assertSame(
            [[1, 2, 'Aria Solberg'], [2, 1, 'Brann Holt']],
            array_map(
                function (MiningSiteScan $scan): array {
                    $resource = $this->mapper->scanToResource($scan);

                    return [$resource->number, $resource->typeCount, $resource->authorName];
                },
                [$first, $second],
            ),
        );
    }

    public function testScanWithoutAuthorHasAnUnknownAuthorNameAndNeitherADefaultNameNorAnError(): void
    {
        $site = $this->persistSite('Belt Aldranette');
        $scan = (new MiningSiteScan())
            ->setSite($site)
            ->setScannedAt(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'))
            ->setTotalUnits(30_000)
            ->setRemainingValue(450_000.0);
        $this->em->persist($scan);
        $this->flushAndClear();
        $reloadedSite = $this->em->find(MiningSite::class, $site->getId());
        \assert($reloadedSite instanceof MiningSite);
        $reloadedScan = $this->em->find(MiningSiteScan::class, $scan->getId());
        \assert($reloadedScan instanceof MiningSiteScan);

        $scanResource = $this->mapper->scanToResource($reloadedScan);
        $siteResource = $this->mapper->siteToResource($reloadedSite);

        self::assertSame(
            [1, null, 1, null],
            [$scanResource->number, $scanResource->authorName, $siteResource->lastScanNumber, $siteResource->lastScanAuthorName],
        );
    }

    private function persistSite(string $label): MiningSite
    {
        $site = (new MiningSite())->setOwner($this->aria)->setLabel($label)->setSolarSystemId(self::ALDRANETTE);
        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }

    private function recordSeries(MiningSite $site, array $authors): void
    {
        $authorIndex = 0;
        foreach (self::VELDSPAR_SERIES as $time => $units) {
            self::mockTime(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'));
            $this->recorder->record($site, [new ScanLine(self::VELDSPAR, $units)], $authors[$authorIndex++]);
        }
    }

    private function authorNamesOf(MiningSite $site): array
    {
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT c.name FROM mining_site_scans s LEFT JOIN users u ON u.id = s.author_id LEFT JOIN characters c ON c.id = u.main_character_id WHERE s.site_id = ? ORDER BY s.scanned_at',
            [(string) $site->getId()],
        );
    }

    private function siteEtasOf(MiningSite $site): array
    {
        return array_map(
            fn (array $row): array => [$this->timeOfDay($row['recent_eta']), $this->timeOfDay($row['trend_eta'])],
            $this->em->getConnection()->fetchAllAssociative('SELECT recent_eta, trend_eta FROM mining_site_scans WHERE site_id = ? ORDER BY scanned_at', [(string) $site->getId()]),
        );
    }

    private function timeOfDay(?string $instant): ?string
    {
        return $instant === null ? null : (new \DateTimeImmutable($instant, new \DateTimeZone('UTC')))->format('H:i:s');
    }
}
