<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Stock;

use App\Dto\Assets\AssetScope;
use App\Entity\CachedCharacterBuyOrder;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\CachedCharacterBuyOrderRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\Service\Planetary\Stock\PlanetaryStockCalculator;
use App\Service\Planetary\Stock\PlanetaryStockReader;
use App\Service\Planetary\Stock\PlanetaryStockRow;
use App\Service\Planetary\Stock\PlanetaryStockSettings;
use App\Tests\Unit\Service\Industry\Stock\StagesCorporationAssets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetaryStockReader::class)]
#[Group('issue-1395')]
final class PlanetaryStockReaderTest extends TestCase
{
    use StagesCorporationAssets;

    private const string ALMA_UUID = '0193a000-0000-7000-8000-000000001395';
    private const string CORA_UUID = '0193a000-0000-7000-8000-000000001396';

    private const int HOME_STATION = 60_003_760;
    private const int HUB_STATION = 60_008_494;
    private const int OTHER_STATION = 60_011_866;

    private const int ROBOTICS = 9848;
    private const int COOLANT = 9832;
    private const int BACTERIA = 2393;

    private User $user;
    private Character $alma;
    private Character $cora;
    private PlanetaryColony $colony;
    private PlanetaryColonyRepository $colonyRepository;
    private PlanetaryInputNeedsCalculator $inputNeedsCalculator;
    private InvTypeRepository $invTypeRepository;
    private AssetBrowseRepository $assetBrowseRepository;
    private CachedCharacterBuyOrderRepository $buyOrderRepository;

    /** @var array<string, array<int, array<int, int>>> */
    private array $assetsByCharacterUuid = [];

    /** @var list<array{string|null, list<int>, list<int>}> */
    private array $assetRequests = [];

    /** @var array<string, array<int, int>> */
    private array $customsByCharacterUuid = [];

    /** @var list<array{string|null, list<int>}> */
    private array $customsRequests = [];

    /** @var list<CachedCharacterBuyOrder> */
    private array $storedOrders = [];

    /** @var list<list<Character>> */
    private array $orderRequests = [];

    /** @var array<string, mixed> */
    private array $inputNeeds = [];

    /** @var array<int, InvType> */
    private array $sdeTypes = [];

    protected function setUp(): void
    {
        $this->stageCorporationAssets();
        $this->connectDirector();
        $this->user = $this->userInTheCorporation();
        $this->alma = $this->user->getCharacters()->first();
        (new \ReflectionProperty(Character::class, 'id'))->setValue($this->alma, Uuid::fromString(self::ALMA_UUID));
        $this->cora = (new Character())->setEveCharacterId(2_132_400_002)->setName('Cora')->setCorporationId(self::STAGED_CORPORATION_ID);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($this->cora, Uuid::fromString(self::CORA_UUID));
        $this->user->addCharacter($this->cora);

        $this->colony = new PlanetaryColony();
        $this->colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $this->colonyRepository->method('findByUser')->willReturnCallback(
            fn (User $user): array => [$this->colony],
        );

        $this->inputNeedsCalculator = $this->createStub(PlanetaryInputNeedsCalculator::class);
        $this->inputNeedsCalculator->method('calculate')->willReturnCallback(
            fn (array $colonies): array => $colonies === [$this->colony] ? $this->inputNeeds : throw new \LogicException('The colonies of the user are expected'),
        );

        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->invTypeRepository->method('findByTypeIds')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->sdeTypes, array_flip($typeIds)),
        );

        $this->assetBrowseRepository = $this->createStub(AssetBrowseRepository::class);
        $this->assetBrowseRepository->method('quantitiesByRootLocation')->willReturnCallback(
            function (AssetScope $scope, array $rootLocationIds, array $typeIds): array {
                $this->assetRequests[] = [$scope->characterId, $rootLocationIds, $typeIds];
                $found = [];
                foreach ($this->assetsByCharacterUuid[$scope->characterId] ?? [] as $locationId => $quantities) {
                    if (!\in_array($locationId, $rootLocationIds, true)) {
                        continue;
                    }
                    foreach ($quantities as $typeId => $quantity) {
                        if (\in_array($typeId, $typeIds, true)) {
                            $found[$locationId][$typeId] = $quantity;
                        }
                    }
                }

                return $found;
            },
        );

        $this->assetBrowseRepository->method('customsOfficeQuantities')->willReturnCallback(
            function (AssetScope $scope, array $typeIds): array {
                $this->customsRequests[] = [$scope->characterId, $typeIds];

                return array_intersect_key($this->customsByCharacterUuid[$scope->characterId] ?? [], array_flip($typeIds));
            },
        );

        $this->buyOrderRepository = $this->createStub(CachedCharacterBuyOrderRepository::class);
        $this->buyOrderRepository->method('findByCharactersAndTypes')->willReturnCallback(
            function (array $characters, array $typeIds): array {
                $this->orderRequests[] = $characters;

                return array_values(array_filter(
                    $this->storedOrders,
                    fn (CachedCharacterBuyOrder $order): bool => \in_array($order->getCharacter(), $characters, true) && \in_array($order->getTypeId(), $typeIds, true),
                ));
            },
        );
    }

    public function testReadingAssemblesTheWeeklyNeedsTheSdeVolumesTheAssetsOfEveryCharacterTheCorporationContainersAndTheBuyOrders(): void
    {
        $this->inputNeeds = [
            'weekly' => [
                $this->weeklyRow(self::ROBOTICS, 'Robotics', 1000.0),
                $this->weeklyRow(self::COOLANT, 'Coolant', 600.0),
            ],
            'complete' => true,
        ];
        $this->sdeTypes = [self::ROBOTICS => $this->sdeType(self::ROBOTICS, 0.15), self::COOLANT => $this->sdeType(self::COOLANT, 1.5)];
        $this->assetsByCharacterUuid = [
            self::ALMA_UUID => [
                self::HOME_STATION => [self::ROBOTICS => 700, self::COOLANT => 60],
                self::HUB_STATION => [self::ROBOTICS => 100],
                self::OTHER_STATION => [self::ROBOTICS => 9_999],
            ],
            self::CORA_UUID => [
                self::HOME_STATION => [self::ROBOTICS => 800, self::COOLANT => 30],
                self::HUB_STATION => [self::ROBOTICS => 400],
            ],
        ];
        $this->storedOrders = [
            $this->order($this->alma, self::ROBOTICS, self::HUB_STATION, 250),
            $this->order($this->alma, self::COOLANT, self::HUB_STATION, 30),
            $this->order($this->cora, self::ROBOTICS, self::OTHER_STATION, 90),
            $this->order($this->cora, self::ROBOTICS, self::HUB_STATION, 150),
        ];
        $office = $this->cacheOffice(self::HOME_STATION, 'Jita IV', 'Jita');
        $container = $this->cacheContainer('Planetary hangar', $office);
        $this->cacheItem(self::ROBOTICS, 100, $container, 'Robotics');
        $this->declareContainer($this->user, 'Planetary hangar', $container, 'Division 2', self::HOME_STATION, 'Jita IV');

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3));

        self::assertCount(2, $stock->rows);
        self::assertSame([self::COOLANT, self::ROBOTICS], array_map(static fn (PlanetaryStockRow $row): int => $row->typeId, $stock->rows));
        self::assertSame(
            ['Robotics', 0.15, 1000.0, 1500, 500, 100, 0, 400, 2.1, 2.5, 500],
            $this->valuesOf($stock->rows[1]),
        );
        self::assertSame(
            ['Coolant', 1.5, 600.0, 90, 0, 0, 0, 30, 0.15, 0.2, 1680],
            $this->valuesOf($stock->rows[0]),
        );
        self::assertNull($stock->corpUnknownReason);
        self::assertTrue($stock->complete);
        self::assertCount(2, $this->assetRequests);
        self::assertSame([self::ALMA_UUID, self::CORA_UUID], array_column($this->assetRequests, 0));
        foreach ($this->assetRequests as [, $rootLocationIds, $typeIds]) {
            self::assertEqualsCanonicalizing([self::HOME_STATION, self::HUB_STATION], $rootLocationIds);
            self::assertEqualsCanonicalizing([self::ROBOTICS, self::COOLANT], $typeIds);
        }
        self::assertCount(1, $this->orderRequests);
        self::assertSame([$this->alma, $this->cora], $this->orderRequests[0]);
    }

    public function testWithoutAHomeLocationOnlyTheHubIsReadAndTheHomeStaysUnknown(): void
    {
        $this->inputNeeds = ['weekly' => [$this->weeklyRow(self::ROBOTICS, 'Robotics', 1000.0)], 'complete' => true];
        $this->sdeTypes = [self::ROBOTICS => $this->sdeType(self::ROBOTICS, 0.15)];
        $this->assetsByCharacterUuid = [
            self::ALMA_UUID => [self::HOME_STATION => [self::ROBOTICS => 700], self::HUB_STATION => [self::ROBOTICS => 100]],
            self::CORA_UUID => [self::HUB_STATION => [self::ROBOTICS => 400]],
        ];

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(null, self::HUB_STATION, 3));

        self::assertCount(1, $stock->rows);
        self::assertSame(['Robotics', 0.15, 1000.0, null, 500, null, 0, 0, null, null, null], $this->valuesOf($stock->rows[0]));
        self::assertFalse($stock->complete);
        foreach ($this->assetRequests as [, $rootLocationIds]) {
            self::assertSame([self::HUB_STATION], $rootLocationIds);
        }
    }

    public function testIncompleteInputNeedsUnknownExtractionAndUnknownSdeVolumeAreNeverReplacedByADefault(): void
    {
        $this->inputNeeds = ['weekly' => [$this->weeklyRow(self::BACTERIA, null, null)], 'complete' => false];
        $this->assetsByCharacterUuid = [self::ALMA_UUID => [self::HOME_STATION => [self::BACTERIA => 40]]];

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3));

        self::assertCount(1, $stock->rows);
        self::assertSame([null, null, null, 40, 0, 0, 0, 0, null, null, null], $this->valuesOf($stock->rows[0]));
        self::assertFalse($stock->complete);
    }

    public function testNoConsumedTypeGivesNoRowAndStaysComplete(): void
    {
        $this->inputNeeds = ['weekly' => [], 'complete' => true];

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3));

        self::assertSame([], $stock->rows);
        self::assertTrue($stock->complete);
        self::assertNull($stock->corpUnknownReason);
    }

    public function testUserWithoutCharacterAsksNoAssetsAndCountsZeroEverywhere(): void
    {
        $this->inputNeeds = ['weekly' => [$this->weeklyRow(self::ROBOTICS, 'Robotics', 1000.0)], 'complete' => true];
        $this->sdeTypes = [self::ROBOTICS => $this->sdeType(self::ROBOTICS, 0.15)];

        $stock = $this->reader()->read(new User(), new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3));

        self::assertCount(1, $stock->rows);
        self::assertSame(['Robotics', 0.15, 1000.0, 0, 0, 0, 0, 0, 0.0, 0.0, 3000], $this->valuesOf($stock->rows[0]));
        self::assertSame([], $this->assetRequests);
        self::assertSame([], $this->customsRequests);
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesOfEveryCharacterAreSummedForTheConsumedTypesOnly(): void
    {
        $this->inputNeeds = [
            'weekly' => [
                $this->weeklyRow(self::ROBOTICS, 'Robotics', 1000.0),
                $this->weeklyRow(self::COOLANT, 'Coolant', 800.0),
            ],
            'complete' => true,
        ];
        $this->sdeTypes = [self::ROBOTICS => $this->sdeType(self::ROBOTICS, 0.15), self::COOLANT => $this->sdeType(self::COOLANT, 1.5)];
        $this->assetsByCharacterUuid = [self::ALMA_UUID => [self::HOME_STATION => [self::ROBOTICS => 300]]];
        $this->customsByCharacterUuid = [
            self::ALMA_UUID => [self::ROBOTICS => 700, self::COOLANT => 40, self::BACTERIA => 5_000],
            self::CORA_UUID => [self::ROBOTICS => 500],
        ];

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3));

        self::assertCount(2, $stock->rows);
        self::assertSame(
            ['Robotics', 0.15, 1000.0, 300, 0, 0, 1_200, 0, 1.5, 1.5, 1_500],
            $this->valuesOf($stock->rows[1]),
        );
        self::assertSame(
            ['Coolant', 1.5, 800.0, 0, 0, 0, 40, 0, 0.05, 0.05, 2_360],
            $this->valuesOf($stock->rows[0]),
        );
        self::assertSame([self::ALMA_UUID, self::CORA_UUID], array_column($this->customsRequests, 0));
        foreach ($this->customsRequests as [, $typeIds]) {
            self::assertEqualsCanonicalizing([self::ROBOTICS, self::COOLANT], $typeIds);
        }
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeIsZeroWhenNoCharacterHoldsTheTypeThere(): void
    {
        $this->inputNeeds = ['weekly' => [$this->weeklyRow(self::BACTERIA, 'Bacteria', 600.0)], 'complete' => true];
        $this->sdeTypes = [self::BACTERIA => $this->sdeType(self::BACTERIA, 0.5)];
        $this->customsByCharacterUuid = [self::ALMA_UUID => [self::ROBOTICS => 700]];

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3));

        self::assertCount(1, $stock->rows);
        self::assertSame(0, $stock->rows[0]->customsOffice);
    }

    #[Group('issue-1458')]
    public function testAnExcludedTypeHasNoRowAndTheOtherRowKeepsItsFigures(): void
    {
        $this->stageBacteriaAndCoolantNeeds();

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3, [self::COOLANT]));

        self::assertSame([self::BACTERIA], array_map(static fn (PlanetaryStockRow $row): int => $row->typeId, $stock->rows));
        self::assertSame(['Bacteria', 0.5, 700.0, 700, 0, 0, 0, 0, 1.0, 1.0, 1_400], $this->valuesOf($stock->rows[0]));
        self::assertTrue($stock->complete);
        foreach ($this->assetRequests as [, , $typeIds]) {
            self::assertSame([self::BACTERIA], $typeIds);
        }
        foreach ($this->customsRequests as [, $typeIds]) {
            self::assertSame([self::BACTERIA], $typeIds);
        }
    }

    #[Group('issue-1458')]
    public function testWithoutExclusionOrWithATypeWithoutNeedBothRowsKeepTheFiguresOfTheCoverage(): void
    {
        $this->stageBacteriaAndCoolantNeeds();

        foreach ([[], [self::ROBOTICS]] as $excludedTypeIds) {
            $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3, $excludedTypeIds));

            self::assertSame([self::BACTERIA, self::COOLANT], array_map(static fn (PlanetaryStockRow $row): int => $row->typeId, $stock->rows));
            self::assertSame(['Bacteria', 0.5, 700.0, 700, 0, 0, 0, 0, 1.0, 1.0, 1_400], $this->valuesOf($stock->rows[0]));
            self::assertSame(['Coolant', 1.5, 140.0, 70, 0, 0, 0, 0, 0.5, 0.5, 350], $this->valuesOf($stock->rows[1]));
        }
    }

    #[Group('issue-1458')]
    public function testExcludingEveryNeededTypeLeavesNoRowAndStaysComplete(): void
    {
        $this->stageBacteriaAndCoolantNeeds();

        $stock = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3, [self::COOLANT, self::BACTERIA]));

        self::assertSame([], $stock->rows);
        self::assertTrue($stock->complete);
    }

    #[Group('issue-1458')]
    public function testCompleteIsComputedOnTheRemainingRowsOnly(): void
    {
        $this->inputNeeds = [
            'weekly' => [
                $this->weeklyRow(self::BACTERIA, 'Bacteria', 700.0),
                $this->weeklyRow(self::COOLANT, 'Coolant', null),
            ],
            'complete' => true,
        ];
        $this->sdeTypes = [self::BACTERIA => $this->sdeType(self::BACTERIA, 0.5), self::COOLANT => $this->sdeType(self::COOLANT, 1.5)];

        $withTheUnknownNeed = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3, []));
        $withoutTheUnknownNeed = $this->reader()->read($this->user, new PlanetaryStockSettings(self::HOME_STATION, self::HUB_STATION, 3, [self::COOLANT]));

        self::assertFalse($withTheUnknownNeed->complete);
        self::assertTrue($withoutTheUnknownNeed->complete);
    }

    private function stageBacteriaAndCoolantNeeds(): void
    {
        $this->inputNeeds = [
            'weekly' => [
                $this->weeklyRow(self::BACTERIA, 'Bacteria', 700.0),
                $this->weeklyRow(self::COOLANT, 'Coolant', 140.0),
            ],
            'complete' => true,
        ];
        $this->sdeTypes = [self::BACTERIA => $this->sdeType(self::BACTERIA, 0.5), self::COOLANT => $this->sdeType(self::COOLANT, 1.5)];
        $this->assetsByCharacterUuid = [
            self::ALMA_UUID => [self::HOME_STATION => [self::BACTERIA => 500, self::COOLANT => 70]],
            self::CORA_UUID => [self::HOME_STATION => [self::BACTERIA => 200]],
        ];
    }

    private function reader(): PlanetaryStockReader
    {
        return new PlanetaryStockReader(
            $this->colonyRepository,
            $this->inputNeedsCalculator,
            $this->invTypeRepository,
            $this->assetBrowseRepository,
            $this->personalStockReader,
            $this->buyOrderRepository,
            new PlanetaryStockCalculator(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function weeklyRow(int $typeId, ?string $typeName, ?float $weeklyNetNeed): array
    {
        return [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'weeklyConsumption' => $weeklyNetNeed,
            'weeklyExtraction' => 0.0,
            'weeklyFactoryOutput' => 0.0,
            'weeklyNetNeed' => $weeklyNetNeed,
        ];
    }

    private function sdeType(int $typeId, ?float $volume): InvType
    {
        return (new InvType())->setTypeId($typeId)->setTypeName('Type '.$typeId)->setVolume($volume);
    }

    private function order(Character $character, int $typeId, int $locationId, int $volumeRemain): CachedCharacterBuyOrder
    {
        static $orderId = 1_395_000;

        return (new CachedCharacterBuyOrder())
            ->setCharacter($character)
            ->setOrderId(++$orderId)
            ->setTypeId($typeId)
            ->setLocationId($locationId)
            ->setPrice(10.0)
            ->setVolumeRemain($volumeRemain)
            ->setVolumeTotal($volumeRemain + 1)
            ->setIssuedAt(new \DateTimeImmutable('2026-11-20'))
            ->setSyncedAt(new \DateTimeImmutable('2026-11-26'));
    }

    /**
     * @return list<string|int|float|null>
     */
    private function valuesOf(PlanetaryStockRow $row): array
    {
        return [$row->typeName, $row->volumePerUnit, $row->weeklyConsumption, $row->home, $row->hub, $row->corp, $row->customsOffice, $row->openOrders, $row->coverageWeeks, $row->coverageWithOrdersWeeks, $row->toBuy];
    }
}
