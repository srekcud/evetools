<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Stock;

use App\Entity\User;
use App\Service\Industry\Stock\PersonalStockContainerReading;
use App\Service\Industry\Stock\PersonalStockReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalStockReader::class)]
#[Group('issue-1324')]
final class PersonalStockReaderTest extends TestCase
{
    use StagesCorporationAssets;

    private const int STATION_JITA = 100;
    private const int STATION_AMARR = 200;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int ZYDRINE = 39;
    private const string PERSO_A = 'Perso A';
    private const string PERSO_B = 'Perso B';
    private const int STALE_HOURS = 49;
    private const int HANGAR_TRITANIUM = 10;

    private User $user;
    private int $officeAtJita;
    private int $officeAtAmarr;

    protected function setUp(): void
    {
        $this->stageCorporationAssets();
        $this->connectDirector();
        $this->user = $this->userInTheCorporation();
        $this->officeAtJita = $this->cacheOffice(self::STATION_JITA, 'Jita IV', 'Jita');
        $this->officeAtAmarr = $this->cacheOffice(self::STATION_AMARR, 'Amarr VIII', 'Amarr');
        $this->hangarQuantities = [self::TRITANIUM => self::HANGAR_TRITANIUM];
        $this->hangarLocations = [self::TRITANIUM => self::jitaHangarLocation(self::HANGAR_TRITANIUM)];
    }

    public function testWithoutDeclaredContainerTheStockIsTheHangarsAlone(): void
    {
        $this->cacheContainer(self::PERSO_A, $this->officeAtJita);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
        self::assertSame([self::TRITANIUM => self::jitaHangarLocation(10)], $stock->locationsByTypeId);
        self::assertSame([], $stock->containers);
        self::assertNull($stock->unknownReason());
    }

    public function testKnownDeclaredContainerAddsItsContentsToTheHangarQuantities(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::TRITANIUM, 5, $persoA, 'Tritanium');
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $declaration = $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame([self::TRITANIUM => 15, self::PYERITE => 2], $this->sortedByTypeId($stock->quantitiesByTypeId));
        $reading = $this->soleReading($stock->containers);
        self::assertSame(
            [$declaration->getId()->toRfc4122(), $persoA, self::PERSO_A, 'Division 2', self::STATION_JITA, 'Jita IV', PersonalStockContainerReading::STATUS_KNOWN, null, 2],
            [$reading->id, $reading->itemId, $reading->name, $reading->divisionName, $reading->stationId, $reading->stationName, $reading->status, $reading->unknownReason, $reading->itemCount],
        );
        self::assertNull($stock->unknownReason());
    }

    public function testCorporationStockOutsideTheDeclaredContainerIsNotMyStock(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::TRITANIUM, 5, $persoA, 'Tritanium');
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->cacheItem(self::ZYDRINE, 50, $this->officeAtJita, 'Zydrine', null, 'CorpSAG2');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame([self::TRITANIUM => 15, self::PYERITE => 2], $this->sortedByTypeId($stock->quantitiesByTypeId));
        self::assertArrayNotHasKey(self::ZYDRINE, $stock->quantitiesByTypeId);
        self::assertArrayNotHasKey(self::ZYDRINE, $stock->locationsByTypeId);
    }

    public function testSubContainerContentsCountButTheSubContainerIsNotAnArticle(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $bulk = $this->cacheContainer('Vrac', $persoA, 'Unlocked');
        $this->cacheItem(self::PYERITE, 3, $bulk, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame([self::TRITANIUM => 10, self::PYERITE => 3], $this->sortedByTypeId($stock->quantitiesByTypeId));
        self::assertArrayNotHasKey(self::STAGED_CONTAINER_TYPE_ID, $stock->quantitiesByTypeId);
        self::assertSame(1, $this->soleReading($stock->containers)->itemCount);
    }

    public function testHangarAndContainerQuantitiesAtTheSameStationAreOneLocation(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::TRITANIUM, 5, $persoA, 'Tritanium');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame([self::TRITANIUM => self::jitaHangarLocation(15)], $stock->locationsByTypeId);
    }

    public function testLocationsStaySortedByDescendingQuantityOnceTheContainerIsMerged(): void
    {
        $this->hangarLocations = [self::TRITANIUM => [
            'total' => 7,
            'locations' => [
                ['locationId' => self::STATION_AMARR, 'locationName' => 'Amarr VIII', 'systemName' => 'Amarr', 'quantity' => 4],
                ['locationId' => self::STATION_JITA, 'locationName' => 'Jita IV', 'systemName' => 'Jita', 'quantity' => 3],
            ],
        ]];
        $this->hangarQuantities = [self::TRITANIUM => 7];
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::TRITANIUM, 5, $persoA, 'Tritanium');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame(12, $stock->locationsByTypeId[self::TRITANIUM]['total']);
        self::assertSame(
            [[self::STATION_JITA, 'Jita IV', 'Jita', 8], [self::STATION_AMARR, 'Amarr VIII', 'Amarr', 4]],
            array_map(
                static fn (array $location): array => [$location['locationId'], $location['locationName'], $location['systemName'], $location['quantity']],
                $stock->locationsByTypeId[self::TRITANIUM]['locations'],
            ),
        );
    }

    public function testContainerContentsOfATypeAbsentFromTheHangarsGetTheirOwnLocation(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtAmarr);
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA, 'Division 2', self::STATION_AMARR, 'Amarr VIII');

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame(
            [self::PYERITE => ['total' => 2, 'locations' => [['locationId' => self::STATION_AMARR, 'locationName' => 'Amarr VIII', 'systemName' => 'Amarr', 'quantity' => 2]]]],
            array_intersect_key($stock->locationsByTypeId, [self::PYERITE => true]),
        );
    }

    public function testEmptyDeclaredContainerIsKnownWithNoContent(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_KNOWN, null, 0], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
        self::assertNull($stock->unknownReason());
    }

    public function testRenamedContainerIsReadByItemIdAndShownUnderItsCurrentName(): void
    {
        $persoA = $this->cacheContainer('Perso A renamed', $this->officeAtJita);
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_KNOWN, 'Perso A renamed'], [$reading->status, $reading->name]);
        self::assertSame(2, $stock->quantitiesByTypeId[self::PYERITE]);
    }

    public function testContainerRemovedFromTheAssetsIsNotFoundAndNeverCountsAsZero(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $declaration = $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->removeFromAssets($persoA);

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame(
            [$declaration->getId()->toRfc4122(), $persoA, self::PERSO_A, 'Division 2', self::STATION_JITA, 'Jita IV', PersonalStockContainerReading::STATUS_UNKNOWN, 'container_not_found', null],
            [$reading->id, $reading->itemId, $reading->name, $reading->divisionName, $reading->stationId, $reading->stationName, $reading->status, $reading->unknownReason, $reading->itemCount],
        );
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
        self::assertSame([self::TRITANIUM => self::jitaHangarLocation(10)], $stock->locationsByTypeId);
        self::assertSame('container_not_found', $stock->unknownReason());
    }

    public function testContainerInADivisionTheUserCannotSeeIsReportedHidden(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita, 'CorpSAG5');
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'container_in_hidden_division', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testCorporationAssetsOlderThan48HoursMakeTheContainerStaleEvenWhenFound(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->assetsSyncedAt = new \DateTimeImmutable('-'.self::STALE_HOURS.' hours');

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'corporation_assets_stale', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testStalenessWinsOverAContainerInAHiddenDivision(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita, 'CorpSAG5');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->assetsSyncedAt = new \DateTimeImmutable('-'.self::STALE_HOURS.' hours');

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame('corporation_assets_stale', $this->soleReading($stock->containers)->unknownReason);
    }

    public function testContainerWhoseOfficeIsNotCachedHasAnUnresolvedStation(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, 1_132_499_999_999);
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'station_unresolved', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testUserWithoutCorporationMakesTheContainerUnknown(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);

        $stock = $this->personalStockReader->of(new User());

        self::assertSame('owner_without_corporation', $stock->unknownReason());
    }

    public function testUserWithoutCorporationKeepsTheHangarQuantities(): void
    {
        $user = new User();
        $this->declareContainer($user, self::PERSO_A, $this->cacheContainer(self::PERSO_A, $this->officeAtJita));

        $stock = $this->personalStockReader->of($user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'owner_without_corporation', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testCorporationWithoutConnectedDirectorMakesTheContainerUnknown(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->membersByCorporationId = [];

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'no_director', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testMissingDirectorWinsOverStaleCorporationAssets(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->membersByCorporationId = [];
        $this->assetsSyncedAt = new \DateTimeImmutable('-'.self::STALE_HOURS.' hours');

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame('no_director', $stock->unknownReason());
    }

    public function testCorporationAssetsNeverSynchronizedMakeTheContainerUnknown(): void
    {
        $this->declareContainer($this->user, self::PERSO_A, 1_132_400_555_555);
        $this->cachedAssets = [];

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'corporation_assets_not_synced', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testUserSeeingNoDivisionMakesTheContainerUnknown(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->visibleDivisions = [];

        $stock = $this->personalStockReader->of($this->user);

        $reading = $this->soleReading($stock->containers);
        self::assertSame([PersonalStockContainerReading::STATUS_UNKNOWN, 'no_visible_division', null], [$reading->status, $reading->unknownReason, $reading->itemCount]);
        self::assertSame([self::TRITANIUM => 10], $stock->quantitiesByTypeId);
    }

    public function testContainerDeclaredInsideAnotherDeclaredContainerIsNotCountedTwice(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $persoB = $this->cacheContainer(self::PERSO_B, $persoA, 'Unlocked');
        $this->cacheItem(self::TRITANIUM, 5, $persoB, 'Tritanium');
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->declareContainer($this->user, self::PERSO_B, $persoB);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame([self::TRITANIUM => 15, self::PYERITE => 2], $this->sortedByTypeId($stock->quantitiesByTypeId));
        self::assertSame(15, $stock->locationsByTypeId[self::TRITANIUM]['total']);
    }

    public function testUnknownReasonIsTheOneOfTheUnknownContainerWhileTheKnownOneCounts(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita);
        $this->cacheItem(self::PYERITE, 2, $persoA, 'Pyerite');
        $persoB = $this->cacheContainer(self::PERSO_B, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->declareContainer($this->user, self::PERSO_B, $persoB);
        $this->removeFromAssets($persoB);

        $stock = $this->personalStockReader->of($this->user);

        self::assertCount(2, $stock->containers);
        self::assertSame(
            [[self::PERSO_A, PersonalStockContainerReading::STATUS_KNOWN], [self::PERSO_B, PersonalStockContainerReading::STATUS_UNKNOWN]],
            array_map(static fn (PersonalStockContainerReading $reading): array => [$reading->name, $reading->status], $stock->containers),
        );
        self::assertSame('container_not_found', $stock->unknownReason());
        self::assertSame([self::TRITANIUM => 10, self::PYERITE => 2], $this->sortedByTypeId($stock->quantitiesByTypeId));
    }

    public function testContainerReadingsAreInNameOrderAndUnknownReasonFollowsThatOrder(): void
    {
        $persoA = $this->cacheContainer(self::PERSO_A, $this->officeAtJita, 'CorpSAG5');
        $persoB = $this->cacheContainer(self::PERSO_B, $this->officeAtJita);
        $this->declareContainer($this->user, self::PERSO_B, $persoB);
        $this->declareContainer($this->user, self::PERSO_A, $persoA);
        $this->removeFromAssets($persoB);

        $stock = $this->personalStockReader->of($this->user);

        self::assertSame(
            [[self::PERSO_A, 'container_in_hidden_division'], [self::PERSO_B, 'container_not_found']],
            array_map(static fn (PersonalStockContainerReading $reading): array => [$reading->name, $reading->unknownReason], $stock->containers),
        );
        self::assertSame('container_in_hidden_division', $stock->unknownReason());
    }

    private static function jitaHangarLocation(int $quantity): array
    {
        return [
            'total' => $quantity,
            'locations' => [['locationId' => self::STATION_JITA, 'locationName' => 'Jita IV', 'systemName' => 'Jita', 'quantity' => $quantity]],
        ];
    }

    private function sortedByTypeId(array $quantitiesByTypeId): array
    {
        ksort($quantitiesByTypeId);

        return $quantitiesByTypeId;
    }

    private function soleReading(array $readings): PersonalStockContainerReading
    {
        self::assertCount(1, $readings);

        return $readings[0];
    }
}
