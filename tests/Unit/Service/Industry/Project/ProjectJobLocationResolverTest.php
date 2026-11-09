<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\Project\ProjectContainerScope;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectJobLocationResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectJobLocationResolver::class)]
#[Group('issue-319')]
final class ProjectJobLocationResolverTest extends TestCase
{
    private const int JOB_ID = 770_001;
    private const int PROJECT_CONTAINER = 1_049_000_000_001;
    private const int PROJECT_SUB_CONTAINER = 1_049_000_000_002;
    private const int PERSONAL_CONTAINER = 1_049_000_000_003;
    private const int CORPORATION_CONTAINER = 1_049_000_000_004;
    private const int HANGAR_STATION = 60_003_760;
    private const int UNKNOWN_LOCATION = 1_049_000_000_999;
    private const int CORPORATION_ID = 98_319_001;

    private array $cachedJobs = [];
    private array $personalAssets = [];
    private bool $characterHasAssets = true;
    private bool $corporationHasAssets = true;
    private ProjectContainerScope $scope;
    private ProjectJobLocationResolver $resolver;

    protected function setUp(): void
    {
        $this->scope = new ProjectContainerScope(
            null,
            [self::PROJECT_CONTAINER, self::PROJECT_SUB_CONTAINER],
            [
                self::PROJECT_CONTAINER => $this->asset(self::PROJECT_CONTAINER, 'ISHTAR-319', 'Corporation Hangar 2', true),
                self::PROJECT_SUB_CONTAINER => $this->asset(self::PROJECT_SUB_CONTAINER, 'Minerals', 'Corporation Hangar 2', true),
                self::CORPORATION_CONTAINER => $this->asset(self::CORPORATION_CONTAINER, 'Corp spare', 'Corporation Hangar 3', true),
            ],
        );
        $this->personalAssets = [
            self::PERSONAL_CONTAINER => $this->asset(self::PERSONAL_CONTAINER, 'Perso', null, false),
        ];

        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findByJobIds')->willReturnCallback(fn (): array => $this->cachedJobs);

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('findPersonalByItemIds')->willReturnCallback(
            fn (array $itemIds): array => array_intersect_key($this->personalAssets, array_flip($itemIds)),
        );
        $assetRepository->method('hasCharacterAssets')->willReturnCallback(fn (): bool => $this->characterHasAssets);
        $assetRepository->method('hasCorporationAssets')->willReturnCallback(fn (): bool => $this->corporationHasAssets);

        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerScopeOf')->willReturnCallback(fn (): ProjectContainerScope => $this->scope);

        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveFacilityName')->willReturnCallback(
            static fn (int $locationId): ?string => $locationId === self::HANGAR_STATION ? 'Jita IV - Moon 4 - Caldari Navy Assembly Plant' : null,
        );

        $this->resolver = new ProjectJobLocationResolver($jobRepository, $assetRepository, $containerService, $calculationService);
    }

    public function testBlueprintInTheProjectContainerAndOutputInASubContainerAreBothInside(): void
    {
        $this->givenJob(self::PROJECT_CONTAINER, self::PROJECT_SUB_CONTAINER);

        self::assertSame(
            [
                'blueprint' => [self::PROJECT_CONTAINER, 'container', 'ISHTAR-319', 'Corporation Hangar 2', 'inside', null],
                'output' => [self::PROJECT_SUB_CONTAINER, 'container', 'Minerals', 'Corporation Hangar 2', 'inside', null],
            ],
            $this->sidesOf(),
        );
    }

    public function testBlueprintTakenFromAPersonalContainerIsOutsideTheProjectContainer(): void
    {
        $this->givenJob(self::PERSONAL_CONTAINER, self::PROJECT_CONTAINER);

        self::assertSame(
            [
                'blueprint' => [self::PERSONAL_CONTAINER, 'container', 'Perso', null, 'outside', null],
                'output' => [self::PROJECT_CONTAINER, 'container', 'ISHTAR-319', 'Corporation Hangar 2', 'inside', null],
            ],
            $this->sidesOf(),
        );
    }

    public function testOutputDeliveredToAStationHangarIsOutsideTheProjectContainer(): void
    {
        $this->givenJob(self::PROJECT_CONTAINER, self::HANGAR_STATION);

        self::assertSame(
            [self::HANGAR_STATION, 'hangar', 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', null, 'outside', null],
            $this->sidesOf()['output'],
        );
    }

    public function testCorporationContainerOutsideTheProjectContainerIsOutside(): void
    {
        $this->givenJob(self::CORPORATION_CONTAINER, self::PROJECT_CONTAINER);

        self::assertSame(
            [self::CORPORATION_CONTAINER, 'container', 'Corp spare', 'Corporation Hangar 3', 'outside', null],
            $this->sidesOf()['blueprint'],
        );
    }

    public function testMissingLocationIsUnknownBecauseTheJobWasSyncedBeforeTheLocationsWereKept(): void
    {
        $this->givenJob(self::PROJECT_CONTAINER, null);

        self::assertSame([null, null, null, null, 'unknown', 'location_missing'], $this->sidesOf()['output']);
    }

    public function testLocationNeitherAnAssetNorAFacilityIsUnknownWhileTheAssetsOfTheJobPilotAreNotSynced(): void
    {
        $this->characterHasAssets = false;
        $this->corporationHasAssets = false;
        $this->givenJob(self::UNKNOWN_LOCATION, self::PROJECT_CONTAINER);

        self::assertSame(
            [self::UNKNOWN_LOCATION, null, null, null, 'unknown', 'assets_not_synced'],
            $this->sidesOf()['blueprint'],
        );
    }

    public function testLocationNeitherAnAssetNorAFacilityIsUnresolvedWhenTheAssetsAreSynced(): void
    {
        $this->givenJob(self::UNKNOWN_LOCATION, self::PROJECT_CONTAINER);

        self::assertSame(
            [self::UNKNOWN_LOCATION, null, null, null, 'unknown', 'location_unresolved'],
            $this->sidesOf()['blueprint'],
        );
    }

    public function testContainerIsUnknownWithTheReasonOfTheScopeWhenTheProjectContainerCannotBeLocated(): void
    {
        $this->scope = new ProjectContainerScope('no_director');
        $this->givenJob(self::PERSONAL_CONTAINER, self::HANGAR_STATION);

        self::assertSame(
            [
                'blueprint' => [self::PERSONAL_CONTAINER, 'container', 'Perso', null, 'unknown', 'no_director'],
                'output' => [self::HANGAR_STATION, 'hangar', 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', null, 'outside', null],
            ],
            $this->sidesOf(),
        );
    }

    public function testCorporationContainerHiddenFromTheOwnerIsNotNamed(): void
    {
        $this->scope = new ProjectContainerScope(null, [self::PROJECT_CONTAINER], [
            self::PROJECT_CONTAINER => $this->asset(self::PROJECT_CONTAINER, 'ISHTAR-319', 'Corporation Hangar 2', true),
        ]);
        $this->givenJob(self::CORPORATION_CONTAINER, self::PROJECT_CONTAINER);

        self::assertSame(
            [self::CORPORATION_CONTAINER, null, null, null, 'unknown', 'location_unresolved'],
            $this->sidesOf()['blueprint'],
        );
    }

    public function testLinkWhoseCachedJobIsGoneHasNoLocations(): void
    {
        $this->cachedJobs = [];

        self::assertSame([], $this->resolver->locationsOf(new IndustryProject(), [$this->matchOf(self::JOB_ID)]));
    }

    private function givenJob(?int $blueprintLocationId, ?int $outputLocationId): void
    {
        $character = (new Character())->setCorporationId(self::CORPORATION_ID);
        $this->cachedJobs = [
            self::JOB_ID => (new CachedIndustryJob())
                ->setCharacter($character)
                ->setJobId(self::JOB_ID)
                ->setBlueprintLocationId($blueprintLocationId)
                ->setOutputLocationId($outputLocationId),
        ];
    }

    private function sidesOf(): array
    {
        $locations = $this->resolver->locationsOf(new IndustryProject(), [$this->matchOf(self::JOB_ID)]);

        return array_map(
            static fn (array $side): array => [$side['locationId'], $side['kind'], $side['name'], $side['divisionName'], $side['status'], $side['unknownReason']],
            $locations[self::JOB_ID],
        );
    }

    private function matchOf(int $jobId): IndustryProjectJobMatch
    {
        return (new IndustryProjectJobMatch())->setEsiJobId($jobId);
    }

    private function asset(int $itemId, string $name, ?string $divisionName, bool $corporationAsset): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId(17_366)
            ->setTypeName('Station Container')
            ->setQuantity(1)
            ->setLocationId(1_049_000_009_000)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('item')
            ->setItemName($name)
            ->setDivisionName($divisionName)
            ->setIsCorporationAsset($corporationAsset);
    }
}
