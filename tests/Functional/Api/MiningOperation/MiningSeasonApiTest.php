<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationVisibility;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-317')]
final class MiningSeasonApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;
    use SeedsMiningBarges;

    private const string MISSING = '<key missing>';
    private const string SEASON_URI = '/api/mining-operations/season';
    private const string AFTER_ENDED_AT = '2026-11-05T21:31:00+00:00';
    private const array SEASON_KEYS_SORTED = ['iskPerHour', 'knownOperationMinutes', 'knownValue', 'operationsCount', 'period', 'playedMinutes', 'players', 'ships', 'sitesBySystem', 'sitesWithoutSystemCount', 'unknownShipCharactersCount', 'unknownValueOperationsCount'];
    private const array EMPTY_SEASON = ['30d', 0, 0, null, 0, 0, null, []];
    private const array SHIP_KEYS = ['shipTypeId', 'shipName', 'charactersCount', 'minutes', 'minedValue', 'iskPerHour', 'isBooster'];

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
    }

    public function testCorporationMemberReadsTheSeasonWithThePresenceOfEachPlayerFromHisEarliestStartSnapshotToTheOperationEndOrHisDeparture(): void
    {
        $this->completeSpodumain(
            ['Aria Solberg' => '20:05:00', 'Aria II' => '20:00:00', 'Brann Holt' => '20:00:00', 'Brann II' => null, 'Cyra Lune' => '20:10:00'],
            [[$this->aria, 'Aria Solberg', 3_000_000], [$this->brann, 'Brann Holt', 2_000_000], [$this->cyra, 'Cyra Lune', 500_000]],
        );
        $this->membershipNamed('Brann Holt')->depart(new \DateTimeImmutable('2026-11-05T21:05:00+00:00'));
        $this->em->flush();

        $season = $this->seasonSeenBy($this->elia);

        self::assertSame(
            [
                'keys' => self::SEASON_KEYS_SORTED,
                'season' => ['30d', 1, 0, 5_500_000, 90, 235, 3_666_667],
                'players' => [
                    ['Aria Solberg', 1, 90, 3_000_000, false],
                    ['Cyra Lune', 1, 80, 500_000, false],
                    ['Brann Holt', 1, 65, 2_000_000, false],
                ],
            ],
            [
                'keys' => $this->sortedKeysOf($season),
                'season' => $this->totalsOf($season),
                'players' => $this->playersOf($season),
            ],
        );
    }

    public function testMemberWithoutAReadStartSnapshotIsNotAPlayerOfTheOperationWhichStillCounts(): void
    {
        $this->completeSpodumain(
            ['Aria Solberg' => '20:00:00', 'Aria II' => null, 'Brann Holt' => '20:30:00', 'Brann II' => null, 'Cyra Lune' => null],
            [[$this->aria, 'Aria Solberg', 3_000_000], [$this->brann, 'Brann Holt', 2_000_000], [$this->cyra, 'Cyra Lune', 500_000]],
        );

        $season = $this->seasonSeenBy($this->aria);

        self::assertSame(
            [['30d', 1, 0, 5_500_000, 90, 150, 3_666_667], [['Aria Solberg', 1, 90, 3_000_000, false], ['Brann Holt', 1, 60, 2_000_000, false]]],
            [$this->totalsOf($season), $this->playersOf($season)],
        );
    }

    public function testClosingOperationCountsItsHoursButItsValueStaysUnknownInsteadOfZero(): void
    {
        $this->takeStartSnapshots(['Aria Solberg' => '20:00:00', 'Cyra Lune' => '20:00:00']);
        $this->spodumain->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->em->flush();

        $season = $this->seasonSeenBy($this->aria);

        self::assertSame(
            [['30d', 1, 1, null, 0, 180, null], [['Aria Solberg', 1, 90, null, true], ['Cyra Lune', 1, 90, null, true]]],
            [$this->totalsOf($season), $this->playersOf($season)],
        );
    }

    public function testUserOfAnotherCorporationAndUserWithoutCorporationGetAnEmptySeasonWithItsNullKeysPresent(): void
    {
        $this->completeSpodumain(['Aria Solberg' => '20:00:00'], [[$this->aria, 'Aria Solberg', 3_000_000]]);

        $otherCorporation = $this->seasonSeenBy($this->cyra);
        $withoutCorporation = $this->seasonSeenBy($this->dorn);

        self::assertSame(
            [self::EMPTY_SEASON, self::EMPTY_SEASON],
            [$this->totalsAndPlayersOf($otherCorporation), $this->totalsAndPlayersOf($withoutCorporation)],
        );
    }

    public function testOnlyCompletedOrClosingCorporationOperationsOfTheUserCorporationAreInTheSeason(): void
    {
        $this->completeSpodumain(['Aria Solberg' => '20:00:00'], [[$this->aria, 'Aria Solberg', 3_000_000]]);
        $this->finishedOperation($this->persistMiningOperation($this->em, $this->elia, 'Private', '2026-11-05T18:00:00+00:00', visibility: MiningOperationVisibility::Private, ownerCharacterNames: ['Elia Varr']), $this->elia, 40_000_000);
        $this->finishedOperation($this->persistMiningOperation($this->em, $this->cyra, 'Other corporation', '2026-11-05T18:00:00+00:00', corporationId: self::C2, ownerCharacterNames: ['Cyra Lune']), $this->cyra, 50_000_000);
        $this->persistMiningOperation($this->em, $this->elia, 'Planned', '2026-11-06T18:00:00+00:00', ownerCharacterNames: ['Elia Varr']);
        $inProgress = $this->persistMiningOperation($this->em, $this->elia, 'In progress', '2026-11-05T21:00:00+00:00', ownerCharacterNames: ['Elia Varr']);
        $inProgress->start(new \DateTimeImmutable('2026-11-05T21:00:00+00:00'), null);
        $this->em->flush();

        $season = $this->seasonSeenBy($this->brann);

        self::assertSame(['30d', 1, 0, 3_000_000, 90, 90, 2_000_000], $this->totalsOf($season));
    }

    public function testPeriodSelectsTheOperationsEndedWithinThirtyDaysNinetyDaysOrEver(): void
    {
        $this->completeSpodumain(['Aria Solberg' => '20:00:00'], [[$this->aria, 'Aria Solberg', 3_000_000]]);
        $this->finishedOperation($this->persistMiningOperation($this->em, $this->elia, 'Thirty-one days ago', '2026-10-05T18:00:00+00:00', ownerCharacterNames: ['Elia Varr']), $this->elia, 7_000_000, '2026-10-05T17:00:00+00:00', '2026-10-05T21:50:00+00:00');
        $this->finishedOperation($this->persistMiningOperation($this->em, $this->elia, 'Hundred days ago', '2026-07-27T18:00:00+00:00', ownerCharacterNames: ['Elia Varr']), $this->elia, 9_000_000, '2026-07-27T17:00:00+00:00', '2026-07-27T21:50:00+00:00');
        $this->em->flush();

        $operationsCountByPeriod = [];
        foreach ([null, '30d', '90d', 'all'] as $period) {
            $season = $this->seasonSeenBy($this->brann, $period);
            $operationsCountByPeriod[$period ?? 'default'] = array_slice($this->totalsOf($season), 0, 2);
        }

        self::assertSame(
            ['default' => ['30d', 1], '30d' => ['30d', 1], '90d' => ['90d', 2], 'all' => ['all', 3]],
            $operationsCountByPeriod,
        );
    }

    public function testUnknownPeriodIsRejected(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::SEASON_URI . '?period=7d');

        self::assertResponseStatusCodeSame(400);
    }

    #[Group('issue-364')]
    public function testSeasonListsTheIskPerHourOfEachShipWithItsNameFromTheFrozenValuesOfItsCharactersAndCountsTheCharactersWithoutShip(): void
    {
        $this->seedMiningBarges();
        $this->recordShips(['Aria Solberg' => self::HULK, 'Aria II' => self::SKIFF, 'Brann Holt' => self::HULK, 'Cyra Lune' => self::ORCA]);
        $this->g1();

        $season = $this->seasonSeenBy($this->elia);

        self::assertSame(
            [
                'ships' => [
                    [self::HULK, 'Hulk', 2, 153, 4_175_721, 1_637_538, false],
                    [self::SKIFF, 'Skiff', 1, 89, 738_093, 497_591, false],
                    [self::ORCA, 'Orca', 1, 89, null, null, true],
                ],
                'unknownShipCharactersCount' => 1,
            ],
            [
                'ships' => $this->shipsOf($season),
                'unknownShipCharactersCount' => array_key_exists('unknownShipCharactersCount', $season) ? $season['unknownShipCharactersCount'] : self::MISSING,
            ],
        );
    }

    #[Group('issue-364')]
    public function testSeasonWithoutOperationHasNoShipAndNoCharacterWithoutShip(): void
    {
        $season = $this->seasonSeenBy($this->elia);

        self::assertSame(
            [[], 0],
            [$this->shipsOf($season), array_key_exists('unknownShipCharactersCount', $season) ? $season['unknownShipCharactersCount'] : self::MISSING],
        );
    }

    private function completeSpodumain(array $startSnapshotTimes, array $minedValues): void
    {
        $this->takeStartSnapshots($startSnapshotTimes);
        $this->spodumain->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->spodumain->complete(
            new \DateTimeImmutable(self::AFTER_ENDED_AT),
            null,
            [],
            array_map(
                fn (array $minedValue, int $position): MiningFrozenMinedValue => new MiningFrozenMinedValue($this->spodumain, $this->spodumain->membershipOf($minedValue[0]), $minedValue[1], $position, (float) $minedValue[2]),
                $minedValues,
                array_keys($minedValues),
            ),
            'Aria Solberg',
        );
        $this->em->flush();
    }

    private function takeStartSnapshots(array $takenAtByCharacter): void
    {
        $this->spodumain->start(new \DateTimeImmutable(self::STARTED_AT), null);
        $startedAt = new \DateTimeImmutable(self::STARTED_AT);
        foreach ($takenAtByCharacter as $characterName => $takenAt) {
            $start = $this->enrolmentNamed($characterName)->scheduleStartSnapshot($startedAt);
            if ($takenAt === null) {
                $start->fail(MiningLedgerSnapshotStatus::ReconnectRequired, 'Token revoked');

                continue;
            }
            $start->take(new \DateTimeImmutable('2026-11-05T' . $takenAt . '+00:00'), $this->reading([]));
        }
        $this->em->flush();
    }

    private function finishedOperation(MiningOperation $operation, User $owner, int $minedValue, string $startedAt = '2026-11-05T18:00:00+00:00', string $endedAt = '2026-11-05T19:00:00+00:00'): void
    {
        $operation->start(new \DateTimeImmutable($startedAt), null);
        $operation->end(new \DateTimeImmutable($endedAt));
        $operation->complete(
            new \DateTimeImmutable($endedAt),
            null,
            [],
            [new MiningFrozenMinedValue($operation, $operation->membershipOf($owner), $owner->getMainCharacter()?->getName(), 0, (float) $minedValue)],
            $owner->getMainCharacter()?->getName(),
        );
    }

    private function seasonSeenBy(User $user, ?string $period = null): array
    {
        $this->as($user);
        $this->request('GET', $period === null ? self::SEASON_URI : self::SEASON_URI . '?period=' . $period);
        self::assertResponseStatusCodeSame(200);

        return $this->responseJson();
    }

    private function totalsOf(array $season): array
    {
        return array_map(
            static fn (string $key): mixed => array_key_exists($key, $season) ? $season[$key] : self::MISSING,
            ['period', 'operationsCount', 'unknownValueOperationsCount', 'knownValue', 'knownOperationMinutes', 'playedMinutes', 'iskPerHour'],
        );
    }

    private function playersOf(array $season): array
    {
        return array_map(
            static fn (array $player): array => array_map(
                static fn (string $key): mixed => array_key_exists($key, $player) ? $player[$key] : self::MISSING,
                ['name', 'operationsCount', 'minutes', 'minedValue', 'hasUnknownValue'],
            ),
            array_key_exists('players', $season) ? $season['players'] : [],
        );
    }

    private function shipsOf(array $season): array
    {
        return array_map(
            static fn (array $ship): array => array_map(
                static fn (string $key): mixed => array_key_exists($key, $ship) ? $ship[$key] : self::MISSING,
                self::SHIP_KEYS,
            ),
            array_key_exists('ships', $season) ? $season['ships'] : [],
        );
    }

    private function totalsAndPlayersOf(array $season): array
    {
        return [...$this->totalsOf($season), $this->playersOf($season)];
    }

    private function sortedKeysOf(array $season): array
    {
        $keys = array_keys($season);
        sort($keys);

        return $keys;
    }
}
