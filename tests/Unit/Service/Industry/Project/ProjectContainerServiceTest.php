<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\ApiResource\Industry\Project\ProjectContainerVerificationResource;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ProjectContainerScope;
use App\Service\Industry\Project\ProjectContainerService;
use App\Tests\Unit\Service\ESI\BuildsCorporationDirectorAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectContainerService::class)]
#[Group('issue-158')]
#[Group('issue-358')]
#[Group('issue-319')]
#[Group('issue-707')]
#[Group('issue-800')]
final class ProjectContainerServiceTest extends TestCase
{
    use BuildsCorporationDirectorAccess;

    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const string CONTAINER_NAME = 'Build';
    private const int OFFICE_ITEM_ID = 1_050_000_000_001;
    private const int STATION_CONTAINER = 17_366;
    private const string ALICE_ID = '0199a1f0-0000-7000-8000-000000358001';
    private const string BOB_ID = '0199a1f0-0000-7000-8000-000000358002';
    private const int ALICE_LIST_DIVISION = 4;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int TRITANIUM_REQUIRED = 500;
    private const int PYERITE_REQUIRED = 800;
    private const int MEXALLON_REQUIRED = 200;

    private const array TYPE_NAMES = [
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::MEXALLON => 'Mexallon',
        self::HYDROGEN_FUEL_BLOCK => 'Hydrogen Fuel Block',
        self::STATION_CONTAINER => 'Station Container',
    ];

    private array $cachedAssets = [];

    private ?CorpAssetVisibility $visibility = null;

    private array $materialLines = [];

    private array $componentLines = [];

    private int $nextItemId = 1_050_000_001_000;

    private CachedAssetRepository $cachedAssetRepository;
    private CorpAssetVisibilityRepository $visibilityRepository;
    private IndustryProjectBomLineRepository $bomItemRepository;
    private ProjectContainerService $service;

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
        $this->cachedAssetRepository->method('lastCachedAtOfCorporationAssets')->willReturnCallback(
            function (int $corporationId): ?\DateTimeImmutable {
                $cachedAtDates = array_map(static fn (CachedAsset $asset): \DateTimeImmutable => $asset->getCachedAt(), $this->cachedAssetsOf($corporationId));

                return $cachedAtDates === [] ? null : max($cachedAtDates);
            },
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

        $this->bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturnCallback(fn (): array => $this->materialLines);
        $this->bomItemRepository->method('findComponentJobsByProject')->willReturnCallback(fn (): array => $this->componentLines);

        $this->corporationMember(self::CORPORATION_ID, ['Director']);

        $this->service = $this->serviceReading($this->cachedAssetRepository);
    }

    public function testProjectWithoutMaterialLinesHasNothingToCheck(): void
    {
        $this->materialLines = [];
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame([], $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)));
    }

    public function testIntermediateConsumedByAnotherJobIsListedAfterTheMaterials(): void
    {
        $this->componentLines = [$this->componentLine(self::HYDROGEN_FUEL_BLOCK, 80)];
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $this->storeInside($build, self::TRITANIUM, 500);
        $this->storeInside($build, self::HYDROGEN_FUEL_BLOCK, 30);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 500, 'verified', null],
                [self::PYERITE, 800, 0, 'missing', null],
                [self::HYDROGEN_FUEL_BLOCK, 80, 30, 'partial', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testIntermediateAbsentFromTheContainerIsMissingAndFullyStoredOneIsVerified(): void
    {
        $this->materialLines = [];
        $this->componentLines = [$this->componentLine(self::HYDROGEN_FUEL_BLOCK, 80), $this->componentLine(self::MEXALLON, 20)];
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::MEXALLON, 25);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 80, 0, 'missing', null],
                [self::MEXALLON, 20, 25, 'verified', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testIntermediateIsUnknownWhenTheContainerCannotBeRead(): void
    {
        $this->materialLines = [];
        $this->componentLines = [$this->componentLine(self::HYDROGEN_FUEL_BLOCK, 80)];

        self::assertSame(
            [[self::HYDROGEN_FUEL_BLOCK, 80, null, 'unknown', 'owner_without_corporation']],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME, $this->ownerOfCorporation(null))),
        );
    }

    public function testIntermediateIsNotFoundWhenNoContainerHasTheProjectName(): void
    {
        $this->materialLines = [];
        $this->componentLines = [$this->componentLine(self::HYDROGEN_FUEL_BLOCK, 80)];
        $this->storeInside($this->containerInHangar('Another box'), self::HYDROGEN_FUEL_BLOCK, 80);
        $this->allowDivisions(2);

        self::assertSame(
            [[self::HYDROGEN_FUEL_BLOCK, 80, null, 'container_not_found', null]],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
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

    public function testItemSeenUnderTwoContainersIsCountedOnceAndTheItemsAfterItAreStillCounted(): void
    {
        $firstBuild = $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1');
        $secondBuild = $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG2');
        $tritaniumItemId = $this->cache(self::TRITANIUM, 120, $secondBuild, 'Unlocked', null, self::CORPORATION_ID);
        $this->storeAgainInside($tritaniumItemId, $firstBuild, self::TRITANIUM, 120);
        $this->storeInside($firstBuild, self::PYERITE, 450);
        $this->allowDivisions(1, 2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 120, 'partial', null],
                [self::PYERITE, 800, 450, 'partial', null],
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
                static fn (ProjectContainerVerificationResource $line): array => [$line->bomItemId, $line->typeId, $line->typeName],
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

    #[Group('issue-800')]
    public function testContainerInADivisionThatIsNotVisibleGivesADedicatedStatusWhileAnotherDivisionIsVisible(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG3'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'container_in_hidden_division', null],
                [self::PYERITE, 800, null, 'container_in_hidden_division', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    #[Group('issue-800')]
    public function testContainerNestedInAHiddenDivisionGivesTheHiddenDivisionStatus(): void
    {
        $hangarContainer = $this->containerInHangar('Outer', 'CorpSAG3');
        $this->containerInside($hangarContainer, self::CONTAINER_NAME);
        $this->allowDivisions(1);

        self::assertSame('container_in_hidden_division', $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME))[0][3]);
    }

    #[Group('issue-800')]
    public function testContainerVisibleInOneDivisionAndHiddenInAnotherIsStillFound(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1'), self::TRITANIUM, 1_000);
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG3'), self::PYERITE, 1_000);
        $this->allowDivisions(1);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 1_000, 'verified', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    #[Group('issue-800')]
    public function testNameFoundNowhereInAFreshSyncStaysContainerNotFound(): void
    {
        $this->storeInside($this->containerInHangar('Spare', 'CorpSAG3'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);

        self::assertSame('container_not_found', $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME))[0][3]);
    }

    #[Group('issue-800')]
    public function testNameFoundNowhereInAStaleSyncGivesTheStaleAssetsStatus(): void
    {
        $this->storeInside($this->containerInHangar('Spare', 'CorpSAG1'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);
        $this->syncedAssetsSince(new \DateInterval('PT49H'));

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'corporation_assets_stale', null],
                [self::PYERITE, 800, null, 'corporation_assets_stale', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    #[Group('issue-800')]
    public function testNameFoundNowhereJustInsideTheFreshnessWindowStaysContainerNotFound(): void
    {
        $this->storeInside($this->containerInHangar('Spare', 'CorpSAG1'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);
        $this->syncedAssetsSince(new \DateInterval('PT47H'));

        self::assertSame('container_not_found', $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME))[0][3]);
    }

    #[Group('issue-800')]
    public function testContainerFoundInAStaleSyncIsStillVerified(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);
        $this->syncedAssetsSince(new \DateInterval('PT49H'));

        self::assertSame('verified', $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME))[0][3]);
    }

    #[Group('issue-800')]
    public function testHiddenDivisionStatusComesBeforeTheStaleAssetsStatus(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG3'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);
        $this->syncedAssetsSince(new \DateInterval('PT49H'));

        self::assertSame('container_in_hidden_division', $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME))[0][3]);
    }

    #[Group('issue-800')]
    public function testEveryLineCarriesTheLastSyncDateOfTheCorporationAssets(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);
        $syncedAt = new \DateTimeImmutable('2026-11-10T08:15:00+00:00');
        foreach ($this->cachedAssets as $asset) {
            $asset->setCachedAt($syncedAt);
        }

        $lines = $this->service->verifyContainer($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertEquals($syncedAt, $line->assetsSyncedAt);
        }
    }

    #[Group('issue-800')]
    public function testLineHasNoSyncDateWhenTheCorporationAssetsWereNeverSynced(): void
    {
        $this->allowDivisions(1);

        $lines = $this->service->verifyContainer($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertSame('corporation_assets_not_synced', $lines[0]->unknownReason);
        self::assertNull($lines[0]->assetsSyncedAt);
    }

    #[Group('issue-800')]
    public function testStoredNameWithSurroundingSpacesFindsTheContainer(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG1'), self::TRITANIUM, 1_000);
        $this->allowDivisions(1);

        self::assertSame('verified', $this->verificationOf($this->projectUsingContainer('  '.self::CONTAINER_NAME.' '))[0][3]);
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

    public function testCheckIsUnknownWithoutDirectorConnectedEvenWithCorporationAssetsInCacheAndBeforeTheNeverSyncedCheck(): void
    {
        $this->membersByCorporationId = [];
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);
        $expected = [
            [self::TRITANIUM, 500, null, 'unknown', 'no_director'],
            [self::PYERITE, 800, null, 'unknown', 'no_director'],
        ];

        self::assertSame($expected, $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)));

        $this->cachedAssets = [];

        self::assertSame($expected, $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)));
    }

    public function testOwnerListedForADivisionHasTheContainerOfThatDivisionChecked(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG'.self::ALICE_LIST_DIVISION), self::TRITANIUM, 600);
        $this->restrictDivisionToPlayers(self::ALICE_LIST_DIVISION, self::ALICE_ID);

        self::assertSame(
            [
                [self::TRITANIUM, 500, 600, 'verified', null],
                [self::PYERITE, 800, 0, 'missing', null],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME, $this->playerOfTheCorporation(self::ALICE_ID))),
        );
    }

    public function testOwnerNotListedForAnyDivisionGetsNoVisibleDivision(): void
    {
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG'.self::ALICE_LIST_DIVISION), self::TRITANIUM, 600);
        $this->restrictDivisionToPlayers(self::ALICE_LIST_DIVISION, self::ALICE_ID);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'no_visible_division'],
                [self::PYERITE, 800, null, 'unknown', 'no_visible_division'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME, $this->playerOfTheCorporation(self::BOB_ID))),
        );
    }

    public function testScopeHoldsTheContainerOfTheProjectAndEveryContainerBelowIt(): void
    {
        $build = $this->containerInHangar(self::CONTAINER_NAME);
        $minerals = $this->containerInside($build, 'Minerals');
        $deep = $this->containerInside($minerals, 'Deep');
        $this->containerInHangar('Spare');
        $this->allowDivisions(2);

        $scope = $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertNull($scope->unknownReason);
        self::assertEqualsCanonicalizing([$build, $minerals, $deep], $scope->containerItemIds);
    }

    public function testScopeHoldsEveryContainerOfTheSameNameInTheVisibleDivisions(): void
    {
        $first = $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG2');
        $second = $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG3');
        $this->containerInHangar(self::CONTAINER_NAME, 'CorpSAG5');
        $this->allowDivisions(2, 3);

        self::assertEqualsCanonicalizing([$first, $second], $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME))->containerItemIds);
    }

    public function testScopeExposesTheVisibleCorporationAssetsByItemIdAndNotTheHiddenOnes(): void
    {
        $visible = $this->containerInHangar('Visible box', 'CorpSAG2');
        $hidden = $this->containerInHangar('Hidden box', 'CorpSAG5');
        $this->containerInHangar(self::CONTAINER_NAME);
        $this->allowDivisions(2);

        $scope = $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertArrayHasKey($visible, $scope->visibleAssetsByItemId);
        self::assertArrayNotHasKey($hidden, $scope->visibleAssetsByItemId);
        self::assertSame('Visible box', $scope->visibleAssetsByItemId[$visible]->getItemName());
    }

    public function testScopeIsUnknownWithTheReasonOfTheContainerCheckWhenItCannotRun(): void
    {
        $this->allowDivisions(2);
        self::assertSame('corporation_assets_not_synced', $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME))->unknownReason);

        $this->containerInHangar('Spare');
        $this->visibility = null;
        self::assertSame('no_visible_division', $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME))->unknownReason);

        $this->allowDivisions(2);
        $this->membersByCorporationId = [];
        self::assertSame('no_director', $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME))->unknownReason);

        self::assertSame('owner_without_corporation', $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME, $this->ownerOfCorporation(null)))->unknownReason);
    }

    #[Group('issue-733')]
    public function testCheckIsUnknownWhenTheCharactersOfTheCorporationHoldNoDirectorRole(): void
    {
        $this->membersByCorporationId = [];
        $this->corporationMember(self::CORPORATION_ID, []);
        $this->corporationMember(self::CORPORATION_ID, ['Accountant']);
        $this->storeInside($this->containerInHangar(self::CONTAINER_NAME), self::TRITANIUM, 1_000);
        $this->allowDivisions(2);

        self::assertSame(
            [
                [self::TRITANIUM, 500, null, 'unknown', 'no_director'],
                [self::PYERITE, 800, null, 'unknown', 'no_director'],
            ],
            $this->verificationOf($this->projectUsingContainer(self::CONTAINER_NAME)),
        );
    }

    public function testScopeIsUnknownWhenTheProjectHasNoContainerName(): void
    {
        $this->containerInHangar('Spare');
        $this->allowDivisions(2);

        $scope = $this->scopeOf($this->projectUsingContainer(null));

        self::assertSame(['no_container_name', []], [$scope->unknownReason, $scope->containerItemIds]);
    }

    public function testScopeIsUnknownWhenNoVisibleContainerHasTheNameOfTheProject(): void
    {
        $spare = $this->containerInHangar('Spare');
        $this->allowDivisions(2);

        $scope = $this->scopeOf($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertSame(['container_not_found', []], [$scope->unknownReason, $scope->containerItemIds]);
        self::assertArrayHasKey($spare, $scope->visibleAssetsByItemId);
    }

    private function scopeOf(IndustryProject $project): ProjectContainerScope
    {
        return $this->service->containerScopeOf($project);
    }

    private function serviceReading(CachedAssetRepository $cachedAssetRepository): ProjectContainerService
    {
        return new ProjectContainerService(
            new VisibleCorporationAssetsFinder($cachedAssetRepository, $this->visibilityRepository),
            $this->bomItemRepository,
            $cachedAssetRepository,
            $this->visibilityRepository,
            $this->corporationDirectorAccess(),
        );
    }

    private function verificationOf(IndustryProject $project): array
    {
        return array_map(
            static fn (ProjectContainerVerificationResource $line): array => [$line->typeId, $line->requiredQuantity, $line->containerQuantity, $line->status, $line->unknownReason],
            $this->service->verifyContainer($project),
        );
    }

    private function projectUsingContainer(?string $containerName, ?User $owner = null): IndustryProject
    {
        return (new IndustryProject())
            ->setOwner($owner ?? $this->ownerOfCorporation(self::CORPORATION_ID))
            ->setContainerName($containerName);
    }

    private function ownerOfCorporation(?int $corporationId): User
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn($corporationId);

        return $owner;
    }

    private function playerOfTheCorporation(string $userId): User
    {
        $player = $this->createStub(User::class);
        $player->method('getId')->willReturn(Uuid::fromString($userId));
        $player->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $player;
    }

    private function restrictDivisionToPlayers(int $divisionNumber, string ...$userIds): void
    {
        $this->visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setRestrictedDivisions([$divisionNumber => $userIds]);
    }

    private function materialLine(int $typeId, int $requiredQuantity): IndustryProjectBomLine
    {
        $materialLine = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($materialLine, Uuid::v4());

        return $materialLine;
    }

    private function componentLine(int $typeId, int $requiredQuantity): IndustryProjectBomLine
    {
        $componentLine = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('manufacturing')
            ->setRuns(1);
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($componentLine, Uuid::v4());

        return $componentLine;
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

    private function storeAgainInside(int $itemId, int $containerItemId, int $typeId, int $quantity): void
    {
        $this->cachedAssets[] = (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setQuantity($quantity)
            ->setLocationId($containerItemId)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('item')
            ->setLocationFlag('Unlocked')
            ->setItemName(null)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
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

    private function syncedAssetsSince(\DateInterval $age): void
    {
        $syncedAt = (new \DateTimeImmutable())->sub($age);
        foreach ($this->cachedAssets as $asset) {
            $asset->setCachedAt($syncedAt);
        }
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
