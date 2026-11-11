<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Enum\IndustryActivityType;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use App\Service\Industry\Project\CreateProjectData;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\SellStructure;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IndustryProjectService::class)]
#[AllowMockObjectsWithoutExpectations]
final class IndustryProjectServiceTest extends TestCase
{
    private const int BLUEPRINT_TYPE_ID_OFFSET = 500_000;
    private const int DEFAULT_MAX_PRODUCTION_LIMIT = 300;
    private const int BASE_TIME_SECONDS = 2_400;
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8058';

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int RIFTER = 587;
    private const int THRASHER = 701;
    private const int SABRE = 22456;
    private const int COMPONENT = 1001;
    private const int REACTION_PRODUCT = 2001;
    private const int FERNITE_CARBIDE = 16672;
    private const int FERNITE = 16657;
    private const int CARBON = 16661;
    private const int BLACKLISTED_TYPE = 9999;
    private const int FIRST_TYPE_OF_THE_BLACKLISTED_GROUP = 11399;
    private const int SECOND_TYPE_OF_THE_BLACKLISTED_GROUP = 11400;
    private const int BLACKLISTED_GROUP = 334;
    private const int HAIL_L = 12779;
    private const int HAIL_L_T1_BLUEPRINT = 898;
    private const int DEPLETED_URANIUM_L = 197;
    private const int PROTOTYPE_AMMUNITION_L = 990_003;
    private const int PROTOTYPE_AMMUNITION_L_T1_BLUEPRINT = 990_013;
    private const int PROTOTYPE_T1_AMMUNITION_L = 990_014;
    private const int MORPHITE = 11399;
    private const int DATACORE_NUCLEAR_PHYSICS = 20423;
    private const int DATACORE_MECHANICAL_ENGINEERING = 20424;
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30_002_187;
    private const int REFINERY_SYSTEM_ID = 30_004_759;
    private const string RAITARU_ID = '01970000-0000-7000-8000-0000000000a1';
    private const string TATARA_ID = '01970000-0000-7000-8000-0000000000a2';
    private const string SOTIYO_ID = '01970000-0000-7000-8000-0000000000a3';
    private const float COPYING_COST_INDEX = 0.02;
    private const float RAITARU_FACILITY_TAX = 0.04;
    private const float SOTIYO_FACILITY_TAX = 0.05;
    private const float SOTIYO_TIME_ROLE_BONUS = 0.70;
    private const float XL_LABORATORY_OPTIMIZATION_II_TIME_REDUCTION_PERCENT = 24.0;

    private const array SDE_TYPE_NAMES = [
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::MEXALLON => 'Mexallon',
        self::FERNITE => 'Fernite',
        self::CARBON => 'Carbon',
        self::MORPHITE => 'Morphite',
        self::DEPLETED_URANIUM_L => 'Depleted Uranium L',
        self::PROTOTYPE_T1_AMMUNITION_L => 'Prototype T1 Ammunition L',
        self::DATACORE_NUCLEAR_PHYSICS => 'Datacore - Nuclear Physics',
        self::DATACORE_MECHANICAL_ENGINEERING => 'Datacore - Mechanical Engineering',
    ];

    private array $recipes = [];

    private array $typeNames = self::SDE_TYPE_NAMES;

    private array $t1ProductByBlueprint = [];

    private array $ownerStructures = [];

    private ?IndustryUserSettings $ownerSettings = null;

    private InMemorySystemCostIndices $costIndices;

    private JitaMarketService&MockObject $jitaMarketService;
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&Stub $connection;
    private InventionService&Stub $inventionService;

    protected function setUp(): void
    {
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->costIndices = InMemorySystemCostIndices::none();
        $this->connection = $this->createStub(Connection::class);
        $this->inventionService = $this->createStub(InventionService::class);

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('findBy')->willReturnCallback(fn (array $criteria): array => array_values(array_map(
            fn (int $typeId): InvType => (new InvType())->setTypeId($typeId)->setTypeName($this->typeNames[$typeId]),
            array_values(array_filter((array) $criteria['typeId'], fn (int $typeId): bool => isset($this->typeNames[$typeId]))),
        )));
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );
    }

    #[Group('issue-141')]
    public function testManufacturedT2ProductWhoseBlueprintHasNoCopyActivityIsStillInventedFromACopyOfTheT1Blueprint(): void
    {
        $this->givenInventedProducts();
        $this->givenRecipe(self::HAIL_L, 'Hail L', [self::MORPHITE => 15], outputPerRun: 5000, maxProductionLimit: 40);
        $project = $this->projectWithItems([[self::HAIL_L, 'Hail L', 3]]);

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', 3, 3, null],
                [self::HAIL_L, 'invention', 'blueprint', 3, 3, null],
                [self::HAIL_L, 'manufacturing', 'final', 3, 15000, null],
            ],
            $this->jobLines($project),
        );
        $this->assertSame(
            [self::MORPHITE => 45, self::DATACORE_NUCLEAR_PHYSICS => 3, self::DATACORE_MECHANICAL_ENGINEERING => 6],
            $this->materialQuantities($project),
        );
    }

    #[Group('issue-141')]
    public function testT2ProductsInventedFromDifferentT1BlueprintsEachGetTheCopyOfTheirOwnT1Blueprint(): void
    {
        $this->givenInventedProducts();
        $this->givenRecipe(self::HAIL_L, 'Hail L', [self::MORPHITE => 15], outputPerRun: 5000, maxProductionLimit: 40);
        $this->givenRecipe(self::PROTOTYPE_AMMUNITION_L, 'Prototype Ammunition L', [self::MORPHITE => 7], outputPerRun: 100, maxProductionLimit: 40, hasCopyActivity: true);
        $project = $this->projectWithItems([[self::HAIL_L, 'Hail L', 3], [self::PROTOTYPE_AMMUNITION_L, 'Prototype Ammunition L', 4]]);

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', 3, 3, null],
                [self::HAIL_L, 'invention', 'blueprint', 3, 3, null],
                [self::HAIL_L, 'manufacturing', 'final', 3, 15000, null],
                [self::PROTOTYPE_AMMUNITION_L, 'invention', 'blueprint', 4, 4, null],
                [self::PROTOTYPE_AMMUNITION_L, 'manufacturing', 'final', 4, 400, null],
                [self::PROTOTYPE_T1_AMMUNITION_L, 'copy', 'blueprint', 4, 4, null],
            ],
            $this->jobLines($project),
        );
        $this->assertSame(
            [self::MORPHITE => 73, self::DATACORE_NUCLEAR_PHYSICS => 3, self::DATACORE_MECHANICAL_ENGINEERING => 26],
            $this->materialQuantities($project),
        );
    }

    public function testCreateProjectBasic(): void
    {
        $user = $this->createUser();
        $this->givenRifter();
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([self::TRITANIUM => 5.5]);

        $project = $this->service()->createProject($user, new CreateProjectData(
            name: 'Fleet Doctrine',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10]],
        ));

        $this->assertSame('Fleet Doctrine', $project->getName());
        $this->assertSame(ProjectStatus::Draft, $project->getStatus());
        $this->assertSame($user, $project->getOwner());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $project->getShortLinkCode());
    }

    #[Group('issue-362')]
    public function testCreateProjectIsPrivateWhenTheDataDoNotSayOtherwise(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Default privacy',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
        ));

        $this->assertTrue($project->isPrivate());
    }

    #[Group('issue-362')]
    public function testCreateProjectTakesThePrivateFlagOfTheData(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Open project',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
            private: false,
        ));

        $this->assertFalse($project->isPrivate());
        $this->assertSame(ProjectStatus::Draft, $project->getStatus());
    }

    #[Group('issue-50')]
    public function testCreateProjectTakesTheSellStructureOfTheDataOrNone(): void
    {
        $this->givenRifter();
        $items = [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]];

        $withStructure = $this->service()->createProject($this->createUser(), new CreateProjectData(name: 'Sold in Keepstar A', items: $items, sellStructure: new SellStructure(1042000000001, 'Keepstar A')));
        $withoutStructure = $this->service()->createProject($this->createUser(), new CreateProjectData(name: 'Sold nowhere', items: $items));

        $this->assertSame([1042000000001, 'Keepstar A'], [$withStructure->getSellStructureId(), $withStructure->getSellStructureName()]);
        $this->assertSame([null, null], [$withoutStructure->getSellStructureId(), $withoutStructure->getSellStructureName()]);
    }

    public function testCreateProjectPersistsAndFlushes(): void
    {
        $this->givenRifter();
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Persist Test',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
        ));
    }

    public function testCreateProjectOwnerAsMember(): void
    {
        $user = $this->createUser();
        $this->givenRifter();

        $project = $this->service()->createProject($user, new CreateProjectData(
            name: 'Test',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
        ));

        $this->assertSame(
            [[$user, ParticipantRole::Owner, ParticipantStatus::Accepted]],
            array_map(
                static fn (IndustryProjectParticipant $member): array => [$member->getUser(), $member->getRole(), $member->getStatus()],
                $project->getMembers()->toArray(),
            ),
        );
    }

    public function testCreateProjectWithItems(): void
    {
        $this->givenRifter();
        $this->givenRecipe(self::SABRE, 'Sabre', [self::TRITANIUM => 20_000, self::MEXALLON => 4_100]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Multi-item',
            items: [
                ['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
                ['typeId' => self::SABRE, 'typeName' => 'Sabre', 'meLevel' => 8, 'teLevel' => 16, 'runs' => 5],
            ],
        ));

        $this->assertSame(
            [[self::RIFTER, 'Rifter', 10, 20, 10, 0], [self::SABRE, 'Sabre', 8, 16, 5, 1]],
            array_map(
                static fn (IndustryProjectProduct $item): array => [
                    $item->getTypeId(), $item->getTypeName(), $item->getMeLevel(), $item->getTeLevel(), $item->getRuns(), $item->getSortOrder(),
                ],
                $project->getItems()->toArray(),
            ),
        );
    }

    public function testCreateProjectEmptyNameStoresNull(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: '',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
        ));

        $this->assertNull($project->getName());
    }

    #[Group('issue-141')]
    public function testBuildBomWithSimpleItem(): void
    {
        $this->givenRifter();
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([self::TRITANIUM => 5.5, self::PYERITE => 10.0]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Simple',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10]],
        ));

        $this->assertSame(
            [[self::TRITANIUM, 'Tritanium', 252_000, 0, false], [self::PYERITE, 'Pyerite', 50_400, 0, false]],
            array_map(
                static fn (IndustryProjectBomLine $material): array => [
                    $material->getTypeId(), $material->getTypeName(), $material->getRequiredQuantity(), $material->getFulfilledQuantity(), $material->isJob(),
                ],
                $this->sortedMaterialBomItems($project),
            ),
        );
        $this->assertSame([[self::RIFTER, 'manufacturing', 'final', 10, 10, null]], $this->jobLines($project));
    }

    #[Group('issue-141')]
    public function testBuildBomWithIntermediateComponents(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::COMPONENT => 10]);
        $this->givenRecipe(self::COMPONENT, 'Advanced Component', [self::TRITANIUM => 250]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'T2 Build',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ));

        $this->assertSame(
            [
                [self::RIFTER, 'Rifter', 'manufacturing', 'final', 5, 10, 20, 5, null],
                [self::COMPONENT, 'Advanced Component', 'manufacturing', 'component', 45, 10, 20, 45, self::RIFTER],
            ],
            array_map(
                static fn (IndustryProjectBomLine $job): array => [
                    $job->getTypeId(), $job->getTypeName(), $job->getActivityType(), $job->getJobGroup(), $job->getRuns(),
                    $job->getMeLevel(), $job->getTeLevel(), $job->getRequiredQuantity(), $job->getParentTypeId(),
                ],
                $this->sortedJobBomItems($project),
            ),
        );
        $this->assertSame([self::TRITANIUM => 10_125], $this->materialQuantities($project));
    }

    public function testBuildBomWithCopyStep(): void
    {
        $this->givenRecipe(self::SABRE, 'Sabre', [self::TRITANIUM => 5_000], hasCopyActivity: true);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'T2 Sabre',
            items: [['typeId' => self::SABRE, 'typeName' => 'Sabre', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 10]],
        ));

        $this->assertSame(
            [[self::SABRE, 'Sabre', 'copy', 'blueprint', 10, 0, 0, 10, true]],
            array_map(
                static fn (IndustryProjectBomLine $job): array => [
                    $job->getTypeId(), $job->getTypeName(), $job->getActivityType(), $job->getJobGroup(), $job->getRuns(),
                    $job->getMeLevel(), $job->getTeLevel(), $job->getRequiredQuantity(), $job->isJob(),
                ],
                $this->jobBomItemsOfActivity($project, 'copy'),
            ),
        );
    }

    #[Group('issue-141')]
    public function testBuildBomMaterialAggregation(): void
    {
        $this->givenRifter();
        $this->givenRecipe(self::SABRE, 'Sabre', [self::TRITANIUM => 20_000, self::MEXALLON => 4_100]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Fleet',
            items: [
                ['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
                ['typeId' => self::SABRE, 'typeName' => 'Sabre', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 5],
            ],
        ));

        $this->assertSame(
            [self::TRITANIUM => 350_000, self::PYERITE => 50_400, self::MEXALLON => 20_090],
            $this->materialQuantities($project),
        );
    }

    public function testBuildBomPricing(): void
    {
        $this->givenRifter();
        $this->jitaMarketService->expects($this->once())
            ->method('getCheapestPercentilePrices')
            ->with($this->callback(static function (array $typeIds): bool {
                sort($typeIds);

                return $typeIds === [self::TRITANIUM, self::PYERITE];
            }))
            ->willReturn([self::TRITANIUM => 5.5, self::PYERITE => 10.0]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Priced',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10]],
        ));

        $this->assertSame([self::TRITANIUM => 5.5, self::PYERITE => 10.0], $this->estimatedPrices($project));
    }

    #[Group('issue-538')]
    public function testBuildBomKeepsPricingAtThePercentileThatItsPriceSourceLabels(): void
    {
        $this->givenRifter();
        $lookups = [];
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturnCallback(
            static function (array $typeIds, float $percentile = 0.05) use (&$lookups): array {
                $lookups[] = $percentile;

                return [self::TRITANIUM => 5.5, self::PYERITE => 10.0];
            },
        );

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Labelled',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10]],
        ));

        $this->assertSame([self::TRITANIUM => 5.5, self::PYERITE => 10.0], $this->estimatedPrices($project));
        $this->assertSame([0.05], $lookups);
        $label = IndustryProjectService::bomPriceSource()->toArray();
        $this->assertArrayHasKey('percentile', $label);
        $this->assertSame(round($lookups[0] * 100, 6), $label['percentile']);
    }

    #[Group('issue-538')]
    public function testBomPriceSourceIsTheJitaSellPercentileFivePercentLabel(): void
    {
        $label = IndustryProjectService::bomPriceSource()->toArray();

        $this->assertSame('jita', $label['market']);
        $this->assertSame('sell', $label['side']);
        $this->assertSame('percentile', $label['method']);
        $this->assertNull($label['coveragePercent']);
        $this->assertArrayHasKey('percentile', $label);
        $this->assertSame(5.0, $label['percentile']);
    }

    public function testBuildBomPricingNullForMissingType(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 25_000]);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([self::TRITANIUM => null]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'No Price',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 10]],
        ));

        $this->assertSame([self::TRITANIUM => 250_000], $this->materialQuantities($project));
        $this->assertSame([self::TRITANIUM => null], $this->estimatedPrices($project));
    }

    #[Group('issue-141')]
    public function testBuildBomWithBlacklistsBuysTheBlacklistedTypesAndTheTypesOfTheBlacklistedGroupsWithoutBuildingThem(): void
    {
        $this->givenComponentsOfTheBlacklists();
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::FIRST_TYPE_OF_THE_BLACKLISTED_GROUP],
            ['type_id' => self::SECOND_TYPE_OF_THE_BLACKLISTED_GROUP],
        ]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Blacklisted',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
            blacklistGroupIds: [self::BLACKLISTED_GROUP],
            blacklistTypeIds: [self::BLACKLISTED_TYPE],
        ));

        $this->assertSame(
            [self::TRITANIUM => 90_000, self::BLACKLISTED_TYPE => 14, self::FIRST_TYPE_OF_THE_BLACKLISTED_GROUP => 32],
            $this->materialQuantities($project),
        );
        $this->assertSame([[self::RIFTER, 'manufacturing', 'final', 5, 5, null]], $this->jobLines($project));
    }

    #[Group('issue-141')]
    public function testBuildBomWithEmptyBlacklistsBuildsEveryComponentThatHasARecipe(): void
    {
        $this->givenComponentsOfTheBlacklists();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'No Blacklist',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ));

        $this->assertSame(
            [self::TRITANIUM => 90_000, self::PYERITE => 504, self::MEXALLON => 2_592],
            $this->materialQuantities($project),
        );
        $this->assertSame(
            [
                [self::RIFTER, 'manufacturing', 'final', 5, 5, null],
                [self::BLACKLISTED_TYPE, 'manufacturing', 'component', 14, 14, self::RIFTER],
                [self::FIRST_TYPE_OF_THE_BLACKLISTED_GROUP, 'manufacturing', 'component', 32, 32, self::RIFTER],
            ],
            $this->jobLines($project),
        );
    }

    #[Group('issue-141')]
    public function testBuildBomReactionNodeHasZeroMeTe(): void
    {
        $this->givenRecipe(self::RIFTER, 'Ship', [self::REACTION_PRODUCT => 20]);
        $this->givenRecipe(self::REACTION_PRODUCT, 'Reaction Product', [self::TRITANIUM => 500], outputPerRun: 100, maxProductionLimit: 1_000, activity: ActivityKind::Reaction);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Reaction',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Ship', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ));

        $this->assertSame(
            [[self::REACTION_PRODUCT, 'component', 1, 0, 0, 100, self::RIFTER]],
            array_map(
                static fn (IndustryProjectBomLine $job): array => [
                    $job->getTypeId(), $job->getJobGroup(), $job->getRuns(), $job->getMeLevel(), $job->getTeLevel(), $job->getRequiredQuantity(), $job->getParentTypeId(),
                ],
                $this->jobBomItemsOfActivity($project, 'reaction'),
            ),
        );
        $this->assertSame([self::TRITANIUM => 500], $this->materialQuantities($project));
    }

    #[Group('issue-141')]
    public function testBuildBomReactionMaterialsAreLeavesNotJobs(): void
    {
        $this->givenRecipe(self::RIFTER, 'T2 Ship', [self::TRITANIUM => 10_000, self::FERNITE_CARBIDE => 40]);
        $this->givenRecipe(self::FERNITE_CARBIDE, 'Fernite Carbide', [self::FERNITE => 100, self::CARBON => 120], outputPerRun: 20, maxProductionLimit: 1_000, activity: ActivityKind::Reaction);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Reaction Materials',
            items: [['typeId' => self::RIFTER, 'typeName' => 'T2 Ship', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ));

        $this->assertSame(
            [[self::FERNITE_CARBIDE, 'Fernite Carbide', true, 9, 180]],
            array_map(
                static fn (IndustryProjectBomLine $job): array => [$job->getTypeId(), $job->getTypeName(), $job->isJob(), $job->getRuns(), $job->getRequiredQuantity()],
                $this->jobBomItemsOfActivity($project, 'reaction'),
            ),
        );
        $this->assertSame([self::TRITANIUM => 45_000, self::FERNITE => 900, self::CARBON => 1_080], $this->materialQuantities($project));
    }

    #[Group('issue-141')]
    public function testBuildBomJobAggregationBuildsASharedComponentOnTheDemandOfAllItsConsumers(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::COMPONENT => 2]);
        $this->givenRecipe(self::THRASHER, 'Thrasher', [self::COMPONENT => 3]);
        $this->givenRecipe(self::COMPONENT, 'Shared Component', [self::TRITANIUM => 60]);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Job Aggregation',
            items: [
                ['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
                ['typeId' => self::THRASHER, 'typeName' => 'Thrasher', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        ));

        $this->assertSame(
            [
                [self::RIFTER, 'manufacturing', 'final', 10, 10, null],
                [self::THRASHER, 'manufacturing', 'final', 5, 5, null],
                [self::COMPONENT, 'manufacturing', 'component', 32, 32, self::RIFTER],
            ],
            $this->jobLines($project),
        );
        $this->assertSame([self::TRITANIUM => 1_728], $this->materialQuantities($project));
    }

    public function testCreateProjectWithOptionalFields(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Full Options',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
            containerName: 'Fleet Doctrine Materials',
            lineRentalRatesOverride: ['ship_t2' => 2500000],
            brokerFeePercent: 2.5,
            salesTaxPercent: 3.0,
        ));

        $this->assertSame('Fleet Doctrine Materials', $project->getContainerName());
        $this->assertSame(['ship_t2' => 2500000], $project->getLineRentalRatesOverride());
        $this->assertSame(2.5, $project->getBrokerFeePercent());
        $this->assertSame(3.0, $project->getSalesTaxPercent());
    }

    #[Group('issue-341')]
    public function testCreateProjectTakesTheSelectionPolicyOfTheData(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifters built anywhere cheaper',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 12]],
            acceptsOutsideFavoriteSystem: true,
            minimumOutsideSaving: 250_000.0,
        ));

        $this->assertSame([true, 250_000.0], [$project->isAcceptsOutsideFavoriteSystem(), $project->getMinimumOutsideSaving()]);
    }

    #[Group('issue-341')]
    public function testCreateProjectRefusesAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumOutsideSavingWhenTheDataDoNotSayOtherwise(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifters at home',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 3]],
        ));

        $this->assertSame([false, IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING], [$project->isAcceptsOutsideFavoriteSystem(), $project->getMinimumOutsideSaving()]);
    }

    #[Group('issue-141')]
    public function testBuildBomIntermediateCopyStep(): void
    {
        $this->givenRecipe(self::RIFTER, 'Ship', [self::COMPONENT => 2]);
        $this->givenRecipe(self::COMPONENT, 'T2 Component', [self::TRITANIUM => 5_000], hasCopyActivity: true);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'With Component Copy',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Ship', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ));

        $this->assertSame(
            [[self::COMPONENT, 'copy', 'blueprint', 9, 9, self::RIFTER]],
            array_values(array_filter($this->jobLines($project), static fn (array $job): bool => $job[2] === 'blueprint')),
        );
        $this->assertSame([self::TRITANIUM => 40_500], $this->materialQuantities($project));
    }

    public function testBuildBomNoMaterialsSkipsPricing(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', []);
        $this->jitaMarketService->expects($this->never())->method('getCheapestPercentilePrices');

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'No Mats',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
        ));

        $this->assertSame([], $this->materialQuantities($project));
        $this->assertSame([[self::RIFTER, 'manufacturing', 'final', 1, 1, null]], $this->jobLines($project));
    }

    #[Group('issue-141')]
    public function testBuildBomSplitsAComponentExceedingItsMaxProductionLimitEvenlyAndRecomputesItsMaterialsJobByJob(): void
    {
        $this->givenRecipe(self::RIFTER, 'Ship', [self::COMPONENT => 7]);
        $this->givenRecipe(self::COMPONENT, 'Component', [self::TRITANIUM => 13], maxProductionLimit: 10);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Split Test',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Ship', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 5]],
        ));

        $this->assertSame([8, 9, 9, 9], $this->runsOf($project, self::COMPONENT, 'manufacturing'));
        $this->assertSame([self::TRITANIUM => 412], $this->materialQuantities($project));
    }

    public function testBuildBomNoSplitWhenWithinLimit(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 10_000], maxProductionLimit: 100);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'No Split',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ));

        $this->assertSame([5], $this->runsOf($project, self::RIFTER, 'manufacturing'));
        $this->assertSame([self::TRITANIUM => 45_000], $this->materialQuantities($project));
    }

    #[Group('issue-141')]
    public function testBuildBomSplitsJobExactMultiple(): void
    {
        $this->givenRecipe(self::RIFTER, 'Ship', [self::COMPONENT => 6]);
        $this->givenRecipe(self::COMPONENT, 'Component', [self::TRITANIUM => 13], maxProductionLimit: 10);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Exact Split',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Ship', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 5]],
        ));

        $this->assertSame([10, 10, 10], $this->runsOf($project, self::COMPONENT, 'manufacturing'));
        $this->assertSame([self::TRITANIUM => 351], $this->materialQuantities($project));
    }

    #[Group('issue-141')]
    public function testBuildBomCopyJobsAreNotSplit(): void
    {
        $this->givenRecipe(self::SABRE, 'Sabre', [self::TRITANIUM => 5_000], maxProductionLimit: 5, hasCopyActivity: true);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Copy No Split',
            items: [['typeId' => self::SABRE, 'typeName' => 'Sabre', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 20]],
        ));

        $this->assertSame([20], $this->runsOf($project, self::SABRE, 'copy'));
        $this->assertSame([5, 5, 5, 5], $this->runsOf($project, self::SABRE, 'manufacturing'));
        $this->assertSame([self::TRITANIUM => 98_000], $this->materialQuantities($project));
    }

    #[Group('issue-141')]
    public function testBuildBomSplitsAFinalJobAtTheMaxProductionLimitOfItsRecipeTheFirstJobsTakingTheRemainder(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 333], maxProductionLimit: 20);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Recipe Limit',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 50]],
        ));

        $this->assertSame(
            [
                [self::RIFTER, 'manufacturing', 'final', 16, 16, null],
                [self::RIFTER, 'manufacturing', 'final', 17, 17, null],
                [self::RIFTER, 'manufacturing', 'final', 17, 17, null],
            ],
            $this->jobLines($project),
        );
        $this->assertSame([self::TRITANIUM => 14_986], $this->materialQuantities($project));
    }

    #[Group('issue-337')]
    #[Group('issue-524')]
    public function testJobAndCopyLinesKeepThePlannedStructureAndHowItWasChosenWhileTheMaterialsHaveNone(): void
    {
        $this->givenOwnerStructuresInHisFavoriteManufacturingSystemAndAnotherSystem();
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 28_000, self::COMPONENT => 10, self::REACTION_PRODUCT => 20]);
        $this->givenRecipe(self::COMPONENT, 'Advanced Component', [self::PYERITE => 250], hasCopyActivity: true);
        $this->givenRecipe(self::REACTION_PRODUCT, 'Reaction Product', [self::MEXALLON => 500], outputPerRun: 100, activity: ActivityKind::Reaction);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifters with their own components',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 4]],
        ));

        $this->assertSame(
            [
                [self::RIFTER, 'manufacturing', 'final', self::RAITARU_ID, 'best_in_favorite_system'],
                [self::COMPONENT, 'copy', 'blueprint', self::RAITARU_ID, 'best_in_favorite_system'],
                [self::COMPONENT, 'manufacturing', 'component', self::RAITARU_ID, 'best_in_favorite_system'],
                [self::REACTION_PRODUCT, 'reaction', 'component', self::TATARA_ID, 'best_configured'],
            ],
            $this->plannedStructuresOfJobLines($project),
        );
        $this->assertSame(
            [self::TRITANIUM => [null, null], self::PYERITE => [null, null], self::MEXALLON => [null, null]],
            $this->plannedStructuresOfMaterialLines($project),
        );
    }

    #[Group('issue-345')]
    public function testReactionJobLineKeepsTheSurplusOfItsIntermediateWhileTheFinalJobLineAndTheMaterialsHaveNone(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 28_000, self::REACTION_PRODUCT => 491]);
        $this->givenRecipe(self::REACTION_PRODUCT, 'Reaction Product', [self::MEXALLON => 500], outputPerRun: 3_000, activity: ActivityKind::Reaction);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifter with a reaction',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1]],
        ));

        $this->assertSame(
            [
                [self::RIFTER, 'final', 1, 1, null],
                [self::REACTION_PRODUCT, 'component', 1, 3_000, 2_509],
            ],
            array_map(
                static fn (IndustryProjectBomLine $job): array => [$job->getTypeId(), $job->getJobGroup(), $job->getRuns(), $job->getRequiredQuantity(), $job->getSurplusQuantity()],
                $this->getJobBomItems($project),
            ),
        );
        $this->assertSame(
            [self::TRITANIUM => null, self::MEXALLON => null],
            array_column(
                array_map(static fn (IndustryProjectBomLine $material): array => [$material->getTypeId(), $material->getSurplusQuantity()], $this->getMaterialBomItems($project)),
                1,
                0,
            ),
        );
    }

    #[Group('issue-345')]
    public function testEveryJobLineOfAReactionSplitByTheMaxProductionLimitCarriesTheSurplusOfTheIntermediate(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::REACTION_PRODUCT => 250]);
        $this->givenRecipe(self::REACTION_PRODUCT, 'Reaction Product', [self::MEXALLON => 500], outputPerRun: 100, maxProductionLimit: 10, activity: ActivityKind::Reaction);

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifters with a split reaction',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 5]],
        ));

        $this->assertSame(
            [50, 50],
            array_map(
                static fn (IndustryProjectBomLine $job): ?int => $job->getSurplusQuantity(),
                $this->jobBomItemsOfActivity($project, 'reaction'),
            ),
        );
        $this->assertSame(
            [1_300],
            [array_sum(array_map(static fn (IndustryProjectBomLine $job): int => $job->getRequiredQuantity(), $this->jobBomItemsOfActivity($project, 'reaction')))],
        );
    }

    #[Group('issue-337')]
    #[Group('issue-524')]
    public function testCopyForTheInventionOfAnInventedProductIsPlannedInTheCopyStructureWhileTheInventionHasNone(): void
    {
        $this->givenOwnerStructuresInHisFavoriteManufacturingSystemAndAnotherSystem();
        $this->givenInventedProducts();
        $this->givenRecipe(self::HAIL_L, 'Hail L', [self::MORPHITE => 15], outputPerRun: 5000, maxProductionLimit: 40);
        $project = $this->projectWithItems([[self::HAIL_L, 'Hail L', 3]]);

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', self::RAITARU_ID, 'best_in_favorite_system'],
                [self::HAIL_L, 'invention', 'blueprint', null, null],
                [self::HAIL_L, 'manufacturing', 'final', self::RAITARU_ID, 'best_in_favorite_system'],
            ],
            $this->plannedStructuresOfJobLines($project),
        );
    }

    #[Group('issue-337')]
    public function testJobLineOfAnOwnerWithoutConfiguredStructureIsPlannedWithoutStructure(): void
    {
        $this->givenRifter();

        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifters without structure',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 4]],
        ));

        $this->assertSame(
            [[self::RIFTER, 'manufacturing', 'final', null, 'not_configured']],
            $this->plannedStructuresOfJobLines($project),
        );
    }

    #[Group('issue-337')]
    public function testRebuildingTheBomUpdatesThePlannedStructureOfAJobLineInPlace(): void
    {
        $this->givenRifter();
        $project = $this->service()->createProject($this->createUser(), new CreateProjectData(
            name: 'Rifters planned before the Raitaru',
            items: [['typeId' => self::RIFTER, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 4]],
        ));
        $rifterJob = $this->getJobBomItems($project)[0];
        $this->givenOwnerStructuresInHisFavoriteManufacturingSystemAndAnotherSystem();

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [[$rifterJob->getId()->toRfc4122()], self::RAITARU_ID, 'best_in_favorite_system'],
            [
                array_map(static fn (IndustryProjectBomLine $job): string => $job->getId()->toRfc4122(), $this->getJobBomItems($project)),
                $rifterJob->getPlannedStructureId(),
                $rifterJob->getPlannedStructureStatus(),
            ],
        );
    }

    #[Group('issue-524')]
    public function testCopyAndCopyForInventionLinesArePlannedInTheOnlyStructureAbleToCopyOutsideAnyFavoriteSystem(): void
    {
        $this->ownerStructures = [
            ProductionStructures::raitaru(self::RAITARU_ID, SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID),
            ProductionStructures::tatara(self::TATARA_ID, SecurityClass::NullSec, self::REFINERY_SYSTEM_ID),
        ];
        $this->givenInventedProducts();
        $this->givenRecipe(self::HAIL_L, 'Hail L', [self::MORPHITE => 15], outputPerRun: 5000, maxProductionLimit: 40);
        $this->givenRecipe(self::THRASHER, 'Thrasher', [self::TRITANIUM => 42_000], hasCopyActivity: true);
        $project = $this->projectWithItems([[self::HAIL_L, 'Hail L', 3], [self::THRASHER, 'Thrasher', 6]]);

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', self::RAITARU_ID, 'best_configured'],
                [self::THRASHER, 'copy', 'blueprint', self::RAITARU_ID, 'best_configured'],
                [self::THRASHER, 'manufacturing', 'final', self::RAITARU_ID, 'best_configured'],
                [self::HAIL_L, 'invention', 'blueprint', null, null],
                [self::HAIL_L, 'manufacturing', 'final', self::RAITARU_ID, 'best_configured'],
            ],
            $this->plannedStructuresOfJobLines($project),
        );
    }

    #[Group('issue-524')]
    public function testCopyIsPlannedInTheStructureOfLowestInstallRateLikeItsCostEvenWhenAMoreRecentOneCopiesFaster(): void
    {
        $this->ownerStructures = [
            ProductionStructures::raitaruWithFacilityTax(self::RAITARU_ID, SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::RAITARU_FACILITY_TAX),
            new ProductionStructure(
                self::SOTIYO_ID,
                StructureType::EngineeringComplex,
                SecurityClass::NullSec,
                self::FAVORITE_MANUFACTURING_SYSTEM_ID,
                new \DateTimeImmutable('2026-06-01 00:00:00'),
                new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
                [],
                new Multiplier(ProductionStructures::SOTIYO_COST_ROLE_BONUS),
                self::SOTIYO_FACILITY_TAX,
                timeRoleBonus: new Multiplier(self::SOTIYO_TIME_ROLE_BONUS),
                scienceRigs: [ProductionStructures::scienceRig(ActivityKind::Copying, self::XL_LABORATORY_OPTIMIZATION_II_TIME_REDUCTION_PERCENT, null)],
            ),
        ];
        $this->ownerSettings = (new IndustryUserSettings())->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SYSTEM_ID);
        $this->costIndices = new InMemorySystemCostIndices([self::FAVORITE_MANUFACTURING_SYSTEM_ID => [ActivityKind::Copying->name => self::COPYING_COST_INDEX]]);
        $this->givenRecipe(self::THRASHER, 'Thrasher', [self::TRITANIUM => 42_000], hasCopyActivity: true);
        $project = $this->projectWithItems([[self::THRASHER, 'Thrasher', 6]]);

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [self::THRASHER, 'copy', 'blueprint', self::RAITARU_ID, 'best_in_favorite_system'],
            $this->plannedStructuresOfJobLines($project)[0],
        );
    }

    #[Group('issue-524')]
    public function testCopyOfAnOwnerWhoseOnlyStructureIsARefineryIsPlannedWithoutStructure(): void
    {
        $this->ownerStructures = [ProductionStructures::tatara(self::TATARA_ID, SecurityClass::NullSec, self::REFINERY_SYSTEM_ID)];
        $this->givenRecipe(self::THRASHER, 'Thrasher', [self::TRITANIUM => 42_000], hasCopyActivity: true);
        $project = $this->projectWithItems([[self::THRASHER, 'Thrasher', 6]]);

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [
                [self::THRASHER, 'copy', 'blueprint', null, 'not_configured'],
                [self::THRASHER, 'manufacturing', 'final', null, 'not_configured'],
            ],
            $this->plannedStructuresOfJobLines($project),
        );
    }

    #[Group('issue-524')]
    public function testRebuildingTheBomPlansTheCopyStructureOfACopyLineBuiltWithoutIt(): void
    {
        $this->givenRecipe(self::THRASHER, 'Thrasher', [self::TRITANIUM => 42_000], hasCopyActivity: true);
        $project = $this->projectWithItems([[self::THRASHER, 'Thrasher', 6]]);
        $this->service()->buildBom($project, $this->createUser());
        $copyLine = $this->copyLineOf($project);
        $copyLine->setPlannedStructureId(null)->setPlannedStructureStatus(null);
        $this->givenOwnerStructuresInHisFavoriteManufacturingSystemAndAnotherSystem();

        $this->service()->buildBom($project, $this->createUser());

        $this->assertSame(
            [$copyLine->getId()->toRfc4122(), self::RAITARU_ID, 'best_in_favorite_system'],
            [$this->copyLineOf($project)->getId()->toRfc4122(), $copyLine->getPlannedStructureId(), $copyLine->getPlannedStructureStatus()],
        );
    }

    private function copyLineOf(IndustryProject $project): IndustryProjectBomLine
    {
        $copyLines = array_values(array_filter($this->getJobBomItems($project), static fn (IndustryProjectBomLine $job): bool => 'copy' === $job->getActivityType()));
        $this->assertCount(1, $copyLines);

        return $copyLines[0];
    }

    private function service(): IndustryProjectService
    {
        $recipes = [];
        foreach ($this->recipes as $productTypeId => $recipe) {
            $materials = [];
            foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
            }
            $recipes[] = new Recipe(
                $productTypeId,
                $recipe['activity'],
                new OutputPerRun($recipe['outputPerRun']),
                $materials,
                new Runs($recipe['maxProductionLimit']),
                self::BASE_TIME_SECONDS,
            );
        }

        return new IndustryProjectService(
            new ProductionPlanning(
                new InMemoryBlueprintCatalog(...$recipes),
                $this->costIndices,
                new InMemoryRigCategories(),
                new StructureTotalCostComparator(new InMemoryMarketPrices([]), new InMemoryAdjustedPrices([]), InMemorySystemCostIndices::none(), new InMemoryJumpDistances(), new InMemoryItemVolumes()),
            ),
            new InMemoryUserProductionStructures([self::OWNER_ID => $this->ownerStructures]),
            $this->jitaMarketService,
            $this->entityManager,
            $this->inventionService,
            $this->activityProductRepository(),
            $this->activityRepository(),
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->settingsRepository(),
            $this->createStub(OriginalBlueprintFinder::class),
        );
    }

    private function settingsRepository(): IndustryUserSettingsRepository
    {
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($this->ownerSettings);

        return $settingsRepository;
    }

    private function givenOwnerStructuresInHisFavoriteManufacturingSystemAndAnotherSystem(): void
    {
        $this->ownerStructures = [
            ProductionStructures::raitaru(self::RAITARU_ID, SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID),
            ProductionStructures::tatara(self::TATARA_ID, SecurityClass::NullSec, self::REFINERY_SYSTEM_ID),
        ];
        $this->ownerSettings = (new IndustryUserSettings())->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SYSTEM_ID);
    }

    private function plannedStructuresOfJobLines(IndustryProject $project): array
    {
        $plannedStructures = array_map(static fn (IndustryProjectBomLine $job): array => [
            $job->getTypeId(),
            $job->getActivityType(),
            $job->getJobGroup(),
            $job->getPlannedStructureId(),
            $job->getPlannedStructureStatus(),
        ], $this->getJobBomItems($project));
        usort($plannedStructures, static fn (array $left, array $right): int => $left <=> $right);

        return $plannedStructures;
    }

    private function plannedStructuresOfMaterialLines(IndustryProject $project): array
    {
        $plannedStructures = [];
        foreach ($this->getMaterialBomItems($project) as $material) {
            $plannedStructures[$material->getTypeId()] = [$material->getPlannedStructureId(), $material->getPlannedStructureStatus()];
        }
        ksort($plannedStructures);

        return $plannedStructures;
    }

    private function activityProductRepository(): IndustryActivityProductRepository
    {
        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct {
                $recipe = $this->recipes[$productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return (new IndustryActivityProduct())
                    ->setTypeId($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET)
                    ->setActivityId($activityId)
                    ->setProductTypeId($productTypeId)
                    ->setQuantity($recipe['outputPerRun']);
            },
        );
        $activityProductRepository->method('findProductsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $products = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($activityIds as $activityId) {
                        $productTypeId = $this->t1ProductByBlueprint[$blueprintTypeId] ?? $blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET;
                        $recipe = $this->recipes[$productTypeId] ?? null;
                        $isT1Blueprint = isset($this->t1ProductByBlueprint[$blueprintTypeId]) && $activityId === IndustryActivityType::Manufacturing->value;
                        if ($isT1Blueprint || ($recipe !== null && self::activityIdOf($recipe['activity']) === $activityId)) {
                            $products[$blueprintTypeId.'-'.$activityId] = (new IndustryActivityProduct())
                                ->setTypeId($blueprintTypeId)
                                ->setActivityId($activityId)
                                ->setProductTypeId($productTypeId)
                                ->setQuantity($recipe['outputPerRun'] ?? 1);
                        }
                    }
                }

                return $products;
            },
        );

        return $activityProductRepository;
    }

    private function activityRepository(): IndustryActivityRepository
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    $recipe = $this->recipes[$blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET] ?? null;
                    if ($recipe === null) {
                        continue;
                    }
                    foreach ($activityIds as $activityId) {
                        $hasActivity = $activityId === IndustryActivityType::Copying->value
                            ? $recipe['hasCopyActivity']
                            : self::activityIdOf($recipe['activity']) === $activityId;
                        if ($hasActivity) {
                            $activities[$blueprintTypeId.'-'.$activityId] = (new IndustryActivity())
                                ->setTypeId($blueprintTypeId)
                                ->setActivityId($activityId)
                                ->setTime(self::BASE_TIME_SECONDS);
                        }
                    }
                }

                return $activities;
            },
        );

        return $activityRepository;
    }

    private function givenRecipe(
        int $productTypeId,
        string $typeName,
        array $baseQuantitiesPerRun,
        int $outputPerRun = 1,
        int $maxProductionLimit = self::DEFAULT_MAX_PRODUCTION_LIMIT,
        bool $hasCopyActivity = false,
        ActivityKind $activity = ActivityKind::Manufacturing,
    ): void {
        $this->recipes[$productTypeId] = [
            'activity' => $activity,
            'outputPerRun' => $outputPerRun,
            'maxProductionLimit' => $maxProductionLimit,
            'hasCopyActivity' => $hasCopyActivity,
            'materials' => $baseQuantitiesPerRun,
        ];
        $this->typeNames[$productTypeId] = $typeName;
    }

    private function givenRifter(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 28_000, self::PYERITE => 5_600]);
    }

    private function givenComponentsOfTheBlacklists(): void
    {
        $this->givenRecipe(self::RIFTER, 'Rifter', [self::TRITANIUM => 20_000, self::BLACKLISTED_TYPE => 3, self::FIRST_TYPE_OF_THE_BLACKLISTED_GROUP => 7]);
        $this->givenRecipe(self::BLACKLISTED_TYPE, 'Blacklisted Component', [self::PYERITE => 40]);
        $this->givenRecipe(self::FIRST_TYPE_OF_THE_BLACKLISTED_GROUP, 'Grouped Component', [self::MEXALLON => 90]);
    }

    private function givenInventedProducts(): void
    {
        $this->inventionService->method('getInventionData')->willReturnCallback(static fn (int $t2TypeId): ?array => match ($t2TypeId) {
            self::HAIL_L => [
                't1BlueprintTypeId' => self::HAIL_L_T1_BLUEPRINT,
                't2BlueprintTypeId' => self::HAIL_L + self::BLUEPRINT_TYPE_ID_OFFSET,
                'probability' => 0.25,
                'baseRuns' => 4,
                'materials' => [
                    ['typeId' => self::DATACORE_NUCLEAR_PHYSICS, 'typeName' => 'Datacore - Nuclear Physics', 'quantity' => 1],
                    ['typeId' => self::DATACORE_MECHANICAL_ENGINEERING, 'typeName' => 'Datacore - Mechanical Engineering', 'quantity' => 2],
                ],
            ],
            self::PROTOTYPE_AMMUNITION_L => [
                't1BlueprintTypeId' => self::PROTOTYPE_AMMUNITION_L_T1_BLUEPRINT,
                't2BlueprintTypeId' => self::PROTOTYPE_AMMUNITION_L + self::BLUEPRINT_TYPE_ID_OFFSET,
                'probability' => 0.5,
                'baseRuns' => 2,
                'materials' => [
                    ['typeId' => self::DATACORE_MECHANICAL_ENGINEERING, 'typeName' => 'Datacore - Mechanical Engineering', 'quantity' => 5],
                ],
            ],
            default => null,
        });
        $this->t1ProductByBlueprint = [
            self::HAIL_L_T1_BLUEPRINT => self::DEPLETED_URANIUM_L,
            self::PROTOTYPE_AMMUNITION_L_T1_BLUEPRINT => self::PROTOTYPE_T1_AMMUNITION_L,
        ];
    }

    private function createUser(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));

        return $user;
    }

    private function projectWithItems(array $items): IndustryProject
    {
        $project = new IndustryProject();
        foreach ($items as $index => [$typeId, $typeName, $runs]) {
            $project->addItem((new IndustryProjectProduct())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRuns($runs)
                ->setMeLevel(2)
                ->setTeLevel(4)
                ->setSortOrder($index));
        }

        return $project;
    }

    private function jobLines(IndustryProject $project): array
    {
        $jobLines = array_map(static fn (IndustryProjectBomLine $job): array => [
            $job->getTypeId(),
            $job->getActivityType(),
            $job->getJobGroup(),
            $job->getRuns(),
            $job->getRequiredQuantity(),
            $job->getParentTypeId(),
        ], $this->getJobBomItems($project));
        usort($jobLines, static fn (array $left, array $right): int => $left <=> $right);

        return $jobLines;
    }

    private function runsOf(IndustryProject $project, int $typeId, string $activityType): array
    {
        $runs = array_map(
            static fn (IndustryProjectBomLine $job): ?int => $job->getRuns(),
            array_filter($this->jobBomItemsOfActivity($project, $activityType), static fn (IndustryProjectBomLine $job): bool => $job->getTypeId() === $typeId),
        );
        sort($runs);

        return $runs;
    }

    private function materialQuantities(IndustryProject $project): array
    {
        $quantities = [];
        foreach ($this->getMaterialBomItems($project) as $material) {
            $quantities[$material->getTypeId()] = $material->getRequiredQuantity();
        }

        ksort($quantities);

        return $quantities;
    }

    private function estimatedPrices(IndustryProject $project): array
    {
        $prices = [];
        foreach ($this->getMaterialBomItems($project) as $material) {
            $prices[$material->getTypeId()] = $material->getEstimatedPrice();
        }

        ksort($prices);

        return $prices;
    }

    private function getMaterialBomItems(IndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $item): bool => !$item->isJob()));
    }

    private function sortedMaterialBomItems(IndustryProject $project): array
    {
        $materials = $this->getMaterialBomItems($project);
        usort($materials, static fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => $left->getTypeId() <=> $right->getTypeId());

        return $materials;
    }

    private function getJobBomItems(IndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $item): bool => $item->isJob()));
    }

    private function sortedJobBomItems(IndustryProject $project): array
    {
        $jobs = $this->getJobBomItems($project);
        usort($jobs, static fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => [$left->getTypeId(), $left->getActivityType()] <=> [$right->getTypeId(), $right->getActivityType()]);

        return $jobs;
    }

    private function jobBomItemsOfActivity(IndustryProject $project, string $activityType): array
    {
        return array_values(array_filter($this->getJobBomItems($project), static fn (IndustryProjectBomLine $item): bool => $item->getActivityType() === $activityType));
    }

    private static function activityIdOf(ActivityKind $activity): int
    {
        return $activity === ActivityKind::Reaction ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
