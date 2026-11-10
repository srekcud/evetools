<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-317')]
final class MiningSeasonBestSitesApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;

    private const string SEASON_URI = '/api/mining-operations/season';
    private const string AFTER_ENDED_AT = '2026-11-05T21:31:00+00:00';
    private const int AMARR = 30_002_187;
    private const int SYSTEM_OUTSIDE_THE_SDE = 30_999_999;
    private const array ROW_KEYS_SORTED = ['bestSite', 'sitesCount', 'solarSystemId', 'solarSystemName', 'unknownSites'];
    private const array BEST_SITE_KEYS_SORTED = ['iskPerHour', 'label', 'observedSeconds'];

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
        $constellation = $this->em->find(MapConstellation::class, 20_000_441);
        \assert($constellation instanceof MapConstellation);
        $this->em->persist((new MapSolarSystem())->setSolarSystemId(self::AMARR)->setSolarSystemName('Amarr')->setConstellation($constellation)->setRegionId(10_000_032));
        $this->em->flush();
    }

    public function testSeasonListsTheBestSiteOfEachSystemFromTheVisibleScansOfTheSitesOfTheCorporationOperationsWithTheSystemNameOfTheSde(): void
    {
        $this->completeSpodumain();
        $aldranette = $this->persistSite($this->spodumain, self::S1, 'Belt Aldranette');
        $this->persistScan($aldranette, '2026-11-05T20:00:00+00:00', 30_000, 3_000_000.0);
        $this->persistScan($aldranette, '2026-11-05T21:00:00+00:00', 12_000, 1_200_000.0);
        $this->persistScan($aldranette, '2026-11-05T21:30:00+00:00', 1_000, 100_000.0, hidden: true);
        $couronne = $this->persistSite($this->spodumain, self::S1, 'Belt Couronne');
        $this->persistScan($couronne, '2026-11-05T20:00:00+00:00', 10_000, 1_000_000.0);
        $this->persistScan($couronne, '2026-11-05T20:30:00+00:00', 5_500, 550_000.0);
        $this->persistScan($this->persistSite($this->spodumain, self::AMARR, 'Belt Amarr'), '2026-11-05T20:10:00+00:00', 7_000, 700_000.0);
        $this->em->flush();

        $season = $this->seasonSeenBy($this->elia);

        self::assertArrayHasKey('sitesBySystem', $season);
        self::assertArrayHasKey('sitesWithoutSystemCount', $season);
        self::assertCount(2, $season['sitesBySystem']);
        self::assertSame(
            [
                'rowKeys' => [self::ROW_KEYS_SORTED, self::ROW_KEYS_SORTED],
                'bestSiteKeys' => self::BEST_SITE_KEYS_SORTED,
                'rows' => [
                    [self::S1, 'Aldranette', 2, ['Belt Aldranette', 1_800_000, 3_600], []],
                    [self::AMARR, 'Amarr', 1, null, [['Belt Amarr', 'fewScans']]],
                ],
                'sitesWithoutSystemCount' => 0,
            ],
            [
                'rowKeys' => array_map($this->sortedKeysOf(...), $season['sitesBySystem']),
                'bestSiteKeys' => $this->sortedKeysOf($season['sitesBySystem'][0]['bestSite']),
                'rows' => $this->rowsOf($season),
                'sitesWithoutSystemCount' => $season['sitesWithoutSystemCount'],
            ],
        );
        self::assertArrayHasKey('bestSite', $season['sitesBySystem'][1]);
        self::assertNull($season['sitesBySystem'][1]['bestSite']);
    }

    public function testSitesOutsideThePeriodOfAnotherCorporationOrWithoutOperationAreNotInTheSeason(): void
    {
        $this->completeSpodumain();
        $this->persistMeasuredSite($this->spodumain, 'Belt Aldranette', 1_800_000.0);
        $thirtyOneDaysAgo = $this->endedOperation($this->elia, 'Thirty-one days ago', '2026-10-05T17:00:00+00:00', '2026-10-05T21:50:00+00:00');
        $this->persistMeasuredSite($thirtyOneDaysAgo, 'Belt Ancienne', 2_400_000.0, self::AMARR);
        $otherCorporation = $this->endedOperation($this->cyra, 'Other corporation', '2026-11-05T18:00:00+00:00', '2026-11-05T19:00:00+00:00', self::C2);
        $this->persistMeasuredSite($otherCorporation, 'Belt Autre Corpo', 3_000_000.0);
        $this->persistMeasuredSite(null, 'Belt Sans Operation', 3_600_000.0);
        $this->em->flush();

        self::assertSame(
            [['Belt Aldranette'], ['Belt Ancienne', 'Belt Aldranette'], ['Belt Autre Corpo']],
            [
                $this->bestLabelsOf($this->seasonSeenBy($this->elia)),
                $this->bestLabelsOf($this->seasonSeenBy($this->elia, 'all')),
                $this->bestLabelsOf($this->seasonSeenBy($this->cyra)),
            ],
        );
    }

    public function testSiteOfAnOperationWithoutFrozenSharesCountsWhileTheValueOfTheOperationStaysUnknown(): void
    {
        $this->spodumain->start(new \DateTimeImmutable(self::STARTED_AT), null);
        $this->spodumain->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->persistMeasuredSite($this->spodumain, 'Belt Aldranette', 1_800_000.0);
        $this->em->flush();

        $season = $this->seasonSeenBy($this->elia);

        self::assertSame(
            [[[self::S1, 'Aldranette', 1, ['Belt Aldranette', 1_800_000, 3_600], []]], 1, null],
            [$this->rowsOf($season), $season['unknownValueOperationsCount'], $season['knownValue']],
        );
    }

    public function testSiteWithoutSolarSystemIsOnlyCountedAndSiteOfASystemAbsentFromTheSdeHasNoSystemName(): void
    {
        $this->completeSpodumain();
        $this->persistMeasuredSite($this->spodumain, 'Belt Perdue', 900_000.0, null);
        $this->persistMeasuredSite($this->spodumain, 'Belt Inconnue', 1_200_000.0, self::SYSTEM_OUTSIDE_THE_SDE);
        $this->em->flush();

        $season = $this->seasonSeenBy($this->elia);

        self::assertSame(
            [[[self::SYSTEM_OUTSIDE_THE_SDE, null, 1, ['Belt Inconnue', 1_200_000, 3_600], []]], 1],
            [$this->rowsOf($season), $season['sitesWithoutSystemCount']],
        );
        self::assertArrayHasKey('solarSystemName', $season['sitesBySystem'][0]);
        self::assertNull($season['sitesBySystem'][0]['solarSystemName']);
    }

    public function testSeasonExposesOnlyTheLabelOfASiteAndNeitherItsIdNorAnyUserId(): void
    {
        $this->completeSpodumain();
        $measured = $this->persistMeasuredSite($this->spodumain, 'Belt Aldranette', 1_800_000.0);
        $unknown = $this->persistSite($this->spodumain, self::S1, 'Belt Solitaire');
        $this->persistScan($unknown, '2026-11-05T20:20:00+00:00', 4_000, 400_000.0);
        $this->em->flush();

        $season = $this->seasonSeenBy($this->elia);

        self::assertCount(1, $season['sitesBySystem']);
        self::assertCount(1, $season['sitesBySystem'][0]['unknownSites']);
        $encoded = json_encode($season['sitesBySystem'], JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'unknownKeys' => ['label', 'reason'],
                'leaks' => [false, false, false, false],
            ],
            [
                'unknownKeys' => $this->sortedKeysOf($season['sitesBySystem'][0]['unknownSites'][0]),
                'leaks' => array_map(
                    static fn (string $identifier): bool => str_contains($encoded, $identifier),
                    [(string) $measured->getId(), (string) $unknown->getId(), (string) $this->aria->getId(), (string) $this->elia->getId()],
                ),
            ],
        );
    }

    private function completeSpodumain(): void
    {
        $this->spodumain->start(new \DateTimeImmutable(self::STARTED_AT), null);
        $this->spodumain->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->spodumain->complete(
            new \DateTimeImmutable(self::AFTER_ENDED_AT),
            null,
            [],
            [new MiningFrozenMinedValue($this->spodumain, $this->spodumain->membershipOf($this->aria), 'Aria Solberg', 0, 3_000_000.0)],
            'Aria Solberg',
        );
        $this->em->flush();
    }

    private function endedOperation(User $owner, string $label, string $startedAt, string $endedAt, int $corporationId = self::C1): MiningOperation
    {
        $operation = $this->persistMiningOperation($this->em, $owner, $label, $startedAt, corporationId: $corporationId, ownerCharacterNames: [(string) $owner->getMainCharacter()?->getName()]);
        $operation->start(new \DateTimeImmutable($startedAt), null);
        $operation->end(new \DateTimeImmutable($endedAt));
        $this->em->flush();

        return $operation;
    }

    private function persistSite(?MiningOperation $operation, ?int $solarSystemId, string $label): MiningSite
    {
        $site = (new MiningSite())
            ->setOwner($this->aria)
            ->setOperation($operation)
            ->setLabel($label)
            ->setSolarSystemId($solarSystemId)
            ->setCreatedAt(new \DateTimeImmutable('2026-11-05T18:00:00+00:00'));
        $this->em->persist($site);

        return $site;
    }

    private function persistMeasuredSite(?MiningOperation $operation, string $label, float $extractedValueInOneHour, ?int $solarSystemId = self::S1): MiningSite
    {
        $site = $this->persistSite($operation, $solarSystemId, $label);
        $this->persistScan($site, '2026-11-05T20:00:00+00:00', 20_000, $extractedValueInOneHour + 500_000.0);
        $this->persistScan($site, '2026-11-05T21:00:00+00:00', 5_000, 500_000.0);

        return $site;
    }

    private function persistScan(MiningSite $site, string $scannedAt, int $units, float $remainingValue, bool $hidden = false): void
    {
        $scan = (new MiningSiteScan())
            ->setSite($site)
            ->setScannedAt(new \DateTimeImmutable($scannedAt))
            ->setTotalUnits($units)
            ->setTotalVolumeM3($units * 0.1)
            ->setRemainingValue($remainingValue);
        if ($hidden) {
            $scan->hide(new \DateTimeImmutable(self::READ_AT));
        }
        $this->em->persist($scan);
    }

    private function seasonSeenBy(User $user, ?string $period = null): array
    {
        $this->as($user);
        $this->request('GET', $period === null ? self::SEASON_URI : self::SEASON_URI . '?period=' . $period);
        self::assertResponseStatusCodeSame(200);

        return $this->responseJson();
    }

    private function rowsOf(array $season): array
    {
        return array_map(
            static fn (array $row): array => [
                $row['solarSystemId'],
                $row['solarSystemName'],
                $row['sitesCount'],
                $row['bestSite'] === null ? null : [$row['bestSite']['label'], $row['bestSite']['iskPerHour'], $row['bestSite']['observedSeconds']],
                array_map(static fn (array $unknown): array => [$unknown['label'], $unknown['reason']], $row['unknownSites']),
            ],
            $season['sitesBySystem'],
        );
    }

    private function bestLabelsOf(array $season): array
    {
        return array_map(static fn (array $row): string => $row['bestSite']['label'], $season['sitesBySystem']);
    }

    private function sortedKeysOf(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}
