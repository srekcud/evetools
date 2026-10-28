<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupContainerVerificationResource;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\GroupIndustryBomItemRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\GroupIndustry\GroupIndustryContainerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustryContainerService::class)]
#[Group('issue-158')]
final class GroupIndustryContainerServiceTest extends TestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const string CONTAINER_NAME = 'Build';
    private const int OFFICE_ITEM_ID = 1_050_000_000_001;
    private const int STATION_CONTAINER = 17_366;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int TRITANIUM_REQUIRED = 500;
    private const int PYERITE_REQUIRED = 800;
    private const int MEXALLON_REQUIRED = 200;

    private const array TYPE_NAMES = [
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::MEXALLON => 'Mexallon',
        self::STATION_CONTAINER => 'Station Container',
    ];

    private array $cachedAssets = [];

    private ?CorpAssetVisibility $visibility = null;

    private array $materialLines = [];

    private int $nextItemId = 1_050_000_001_000;

    private CachedAssetRepository $cachedAssetRepository;
    private CorpAssetVisibilityRepository $visibilityRepository;
    private GroupIndustryBomItemRepository $bomItemRepository;
    private GroupIndustryContainerService $service;

    protected function setUp(): void
    {
        $this->materialLines = [
            $this->materialLine(self::TRITANIUM, self::TRITANIUM_REQUIRED),
            $this->materialLine(self::PYERITE, self::PYERITE_REQUIRED),
        ];

        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->cachedAssetRepository->method('hasCorporationAssets')->willReturnCallback(
            fn (int $corporationId): bool => $this->cachedAssetsOf($corporationId) !== [],
        );
        $this->cachedAssetRepository->method('findByCorporationAndDivisions')->willReturnCallback(
            fn (int $corporationId, array $divisionNumbers): array => array_values(array_filter(
                $this->cachedAssetsOf($corporationId),
                static fn (CachedAsset $asset): bool => \in_array(
                    $asset->getLocationFlag(),
                    array_map(static fn (int $divisionNumber): string => 'CorpSAG'.$divisionNumber, $divisionNumbers),
                    true,
                ),
            )),
        );
        $this->cachedAssetRepository->method('findContentsOfContainers')->willReturnCallback(
            fn (int $corporationId, array $containerItemIds): array => $this->contentsOf($corporationId, $containerItemIds),
        );

        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->visibilityRepository->method('findByCorporationId')->willReturnCallback(
            fn (int $corporationId): ?CorpAssetVisibility => $corporationId === self::CORPORATION_ID ? $this->visibility : null,
        );

        $this->bomItemRepository = $this->createStub(GroupIndustryBomItemRepository::class);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturnCallback(fn (): array => $this->materialLines);

        $this->service = $this->serviceReading($this->cachedAssetRepository);
    }

    public function testProjectWithoutMaterialLinesHasNothingToCheck(): void
    {
        $this->materialLines = [];
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame([], $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)));
    }

    public function testWithoutMaterialLinesTheCorporationAssetsAreNotRead(): void
    {
        $this->materialLines = [];
        $cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $cachedAssetRepository->expects(self::never())->method('hasCorporationAssets');
        $cachedAssetRepository->expects(self::never())->method('findByCorporationAndDivisions');

        self::assertSame([], $this->serviceReading($cachedAssetRepository)->verifyContainer($this->projectUsingContainer(self::CONTAINER_NAME)));
    }

    public function testProjectWithoutContainerNameHasNothingToCheck(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame([], $this->verificationOf($this->projectUsingContainer(null)));
    }

    public function testProjectWithoutContainerNameHasNothingToCheckEvenWhenTheOwnerHasNoCorporation(): void
    {
        self::assertSame([], $this->verificationOf($this->projectUsingContainer(null, new User())));
    }

    public function testContentsOfASubContainerCountTowardsTheContainer(): void
    {
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 1_000);
        $this->storeInside($this->containerInside($build, 'Minerals'), self::PYERITE, 240);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 1_000, 'verified', null],
                [self::PYERITE, 800, 240, 'partial', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testQuantitiesOfOneTypeInTheContainerAndInItsSubContainerAddUp(): void
    {
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 450);
        $this->storeInside($this->containerInside($build, 'Minerals'), self::TRITANIUM, 100);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 550, 'verified', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContentsTwoLevelsBelowTheContainerAreCounted(): void
    {
        $minerals = $this->containerInside($this->containerInHangar(self::CONTAINER_NAME), 'Minerals');
        $this->storeInside($minerals, self::PYERITE, 600);
        $this->storeInside($this->containerInside($minerals, 'Bag'), self::PYERITE, 300);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 0, 'missing', null],
                [self::PYERITE, 800, 900, 'verified', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainerStoredInAnotherContainerOfTheHangarIsFoundWithItsSubContainers(): void
    {
        $build = $this->containerInside($this->containerInHangar('Outer'), self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 500);
        $this->storeInside($this->containerInside($build, 'Minerals'), self::PYERITE, 800);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 500, 'verified', null],
                [self::PYERITE, 800, 800, 'verified', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContentsFiveLevelsBelowTheHangarAreCounted(): void
    {
        $level = $this->containerInHangar(self::CONTAINER_NAME);
        foreach (['First box', 'Second box', 'Third box', 'Fourth box'] as $boxName) {
            $level = $this->containerInside($level, $boxName);
        }
        $this->storeInside($level, self::PYERITE, 800);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 0, 'missing', null],
                [self::PYERITE, 800, 800, 'verified', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainersOfTheSameNameInTwoVisibleDivisionsAddUp(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1'), self::TRITANIUM, 300);
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG2'), self::TRITANIUM, 300);
        $this->allowDivisions(1, 2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 600, 'verified', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainerOfTheSameNameInsideTheContainerIsCountedOnce(): void
    {
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 100);
        $this->storeInside($this->containerInside($build, self::CONTAINER_NAME), self::TRITANIUM, 200);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 300, 'partial', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testOnlyTheContentsOfTheNamedContainerAreCounted(): void
    {
        $this->storeInHangar(self::TRITANIUM, 900);
        $spare = $this->containerInHangar('Spare');
        $this->storeInside($spare, self::TRITANIUM, 700);
        $this->storeInside($this->containerInside($spare, 'Minerals'), self::PYERITE, 600);
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 200);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 200, 'partial', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainerInAVisibleDivisionIgnoresTheContainerOfTheSameNameInADivisionThatIsNotVisible(): void
    {
        $hiddenBuild = $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1');
        $this->storeInside($hiddenBuild, self::TRITANIUM, 400);
        $this->storeInside($this->containerInside($hiddenBuild, 'Minerals'), self::PYERITE, 500);
        $visibleBuild = $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG2');
        $this->storeInside($visibleBuild, self::TRITANIUM, 150);
        $this->storeInside($this->containerInside($visibleBuild, 'Minerals'), self::PYERITE, 300);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 150, 'partial', null],
                [self::PYERITE, 800, 300, 'partial', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testEmptyContainerMakesEveryMaterialMissing(): void
    {
        $this->containerInHangar(self::CONTAINER_NAME);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 0, 'missing', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testMaterialAtTheRequiredQuantityIsVerifiedAndOneUnitBelowIsPartial(): void
    {
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 500);
        $this->storeInside($build, self::PYERITE, 799);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 500, 'verified', null],
                [self::PYERITE, 800, 799, 'partial', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testSingleUnitInTheContainerIsPartial(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 1, 'partial', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testEachMaterialLineGetsItsOwnStatusInBomOrder(): void
    {
        $this->materialLines = [
            $this->materialLine(self::MEXALLON, self::MEXALLON_REQUIRED),
            $this->materialLine(self::PYERITE, self::PYERITE_REQUIRED),
            $this->materialLine(self::TRITANIUM, self::TRITANIUM_REQUIRED),
        ];
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 2_000);
        $this->storeInside($this->containerInside($build, 'Minerals'), self::PYERITE, 100);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::MEXALLON, 200, 0, 'missing', null],
                [self::PYERITE, 800, 100, 'partial', null],
                [self::TRITANIUM, 500, 2_000, 'verified', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testEachLineCarriesItsBomLineIdentifierAndTypeName(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::PYERITE, 240);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [(string) $this->materialLines[0]->getId(), self::TRITANIUM, 'Tritanium'],
                [(string) $this->materialLines[1]->getId(), self::PYERITE, 'Pyerite'],
            ],
            array_map(
                static fn (GroupContainerVerificationResource $line): array => [$line->bomItemId, $line->typeId, $line->typeName],
                $this->service->verifyContainer($this->projectUsingContainer(self::CONTAINER_NAME)),
            ),
        );
    }

    public function testCorporationAssetsWithoutContainerOfThatNameGiveContainerNotFound(): void
    {
        $this->storeInside($this->containerInHangar('Spare'), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'container_not_found', null],
                [self::PYERITE, 800, null, 'container_not_found', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainerInADivisionThatIsNotVisibleIsNotFound(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG2'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'container_not_found', null],
                [self::PYERITE, 800, null, 'container_not_found', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainerInTheDeliveriesHangarIsNotFoundEvenWithEveryDivisionVisible(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpDeliveries'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1, 2, 3, 4, 5, 6, 7);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'container_not_found', null],
                [self::PYERITE, 800, null, 'container_not_found', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testContainerNameIsCaseSensitive(): void
    {
        $this->storeInside($this->containerInHangar('build'), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'container_not_found', null],
                [self::PYERITE, 800, null, 'container_not_found', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testCheckIsUnknownWhileTheCorporationAssetsWereNeverSynced(): void
    {
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'corporation_assets_not_synced'],
                [self::PYERITE, 800, null, 'unknown', 'corporation_assets_not_synced'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testAssetsOfAnotherCorporationDoNotMakeTheAssetsOfTheOwnerCorporationSynced(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG2', self::OTHER_CORPORATION_ID), self::TRITANIUM, 1_000, self::OTHER_CORPORATION_ID);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'corporation_assets_not_synced'],
                [self::PYERITE, 800, null, 'unknown', 'corporation_assets_not_synced'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testCheckIsUnknownWithoutDivisionVisibilityForTheCorporation(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'no_visible_division'],
                [self::PYERITE, 800, null, 'unknown', 'no_visible_division'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testCheckIsUnknownWhenNoDivisionIsVisible(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions();

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'no_visible_division'],
                [self::PYERITE, 800, null, 'unknown', 'no_visible_division'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testNeverSyncedAssetsComeBeforeTheMissingDivisionVisibility(): void
    {
        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'corporation_assets_not_synced'],
                [self::PYERITE, 800, null, 'unknown', 'corporation_assets_not_synced'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testCheckIsUnknownWhenTheOwnerHasNoMainCharacter(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'owner_without_corporation'],
                [self::PYERITE, 800, null, 'unknown', 'owner_without_corporation'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME, new User())),
        );
    }

    public function testCheckIsUnknownWhenTheMainCharacterOfTheOwnerHasNoCorporation(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'owner_without_corporation'],
                [self::PYERITE, 800, null, 'unknown', 'owner_without_corporation'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME, $this->ownerOfCorporation(null))),
        );
    }

    public function testOwnerWithoutCorporationComesBeforeNeverSyncedAssets(): void
    {
        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'owner_without_corporation'],
                [self::PYERITE, 800, null, 'unknown', 'owner_without_corporation'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME, new User())),
        );
    }

    private function serviceReading(CachedAssetRepository $cachedAssetRepository): GroupIndustryContainerService
    {
        return new GroupIndustryContainerService(
            new VisibleCorporationAssetsFinder($cachedAssetRepository, $this->visibilityRepository),
            $this->bomItemRepository,
            $cachedAssetRepository,
            $this->visibilityRepository,
        );
    }

    private function verificationOf(GroupIndustryProject $project): array
    {
        return array_map(
            static fn (GroupContainerVerificationResource $line): array => [$line->typeId, $line->requiredQuantity, $line->containerQuantity, $line->status, $line->unknownReason],
            $this->service->verifyContainer($project),
        );
    }

    private function projectUsingContainer(?string $containerName, ?User $owner = null): GroupIndustryProject
    {
        return (new GroupIndustryProject())
            ->setOwner($owner ?? $this->ownerOfCorporation(self::CORPORATION_ID))
            ->setContainerName($containerName);
    }

    private function ownerOfCorporation(?int $corporationId): User
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn($corporationId);

        return $owner;
    }

    private function materialLine(int $typeId, int $requiredQuantity): GroupIndustryBomItem
    {
        $materialLine = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        (new \ReflectionProperty(GroupIndustryBomItem::class, 'id'))->setValue($materialLine, Uuid::v4());

        return $materialLine;
    }

    private function allowDivisions(int ...$divisionNumbers): void
    {
        $this->visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions($divisionNumbers);
    }

    private function containerInHangar(string $name, string $hangarFlag = 'CorpSAG2', int $corporationId = self::CORPORATION_ID): int
    {
        return $this->cache(self::STATION_CONTAINER, 1, self::OFFICE_ITEM_ID, $hangarFlag, $name, $corporationId);
    }

    private function containerInside(int $containerItemId, string $name): int
    {
        return $this->cache(self::STATION_CONTAINER, 1, $containerItemId, 'Unlocked', $name, self::CORPORATION_ID);
    }

    private function storeInside(int $containerItemId, int $typeId, int $quantity, int $corporationId = self::CORPORATION_ID): void
    {
        $this->cache($typeId, $quantity, $containerItemId, 'Unlocked', null, $corporationId);
    }

    private function storeInHangar(int $typeId, int $quantity): void
    {
        $this->cache($typeId, $quantity, self::OFFICE_ITEM_ID, 'CorpSAG2', null, self::CORPORATION_ID);
    }

    private function cache(int $typeId, int $quantity, int $locationId, string $locationFlag, ?string $itemName, int $corporationId): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('item')
            ->setLocationFlag($locationFlag)
            ->setItemName($itemName)
            ->setCorporationId($corporationId)
            ->setIsCorporationAsset(true);

        return $itemId;
    }

    private function cachedAssetsOf(int $corporationId): array
    {
        return array_values(array_filter(
            $this->cachedAssets,
            static fn (CachedAsset $asset): bool => $asset->getCorporationId() === $corporationId,
        ));
    }

    private function contentsOf(int $corporationId, array $containerItemIds): array
    {
        $contents = [];
        while ($containerItemIds !== []) {
            $level = array_values(array_filter(
                $this->cachedAssetsOf($corporationId),
                static fn (CachedAsset $asset): bool => \in_array($asset->getLocationId(), $containerItemIds, true),
            ));
            $contents = [...$contents, ...$level];
            $containerItemIds = array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $level);
        }

        return $contents;
    }
}
