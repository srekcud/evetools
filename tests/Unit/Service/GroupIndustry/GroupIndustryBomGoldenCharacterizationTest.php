<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\IndustryActivityType;
use App\Exception\InventionProbabilityMissingException;
use App\Exception\InventionT1ProductMissingException;
use App\Exception\TypeMissingFromSdeException;
use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\LeafCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustryProjectService::class)]
#[AllowMockObjectsWithoutExpectations]
final class GroupIndustryBomGoldenCharacterizationTest extends TestCase
{
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../Fixtures/Golden/sde-blueprint-recipes.json';
    private const string NOMAD_GOLDEN = __DIR__.'/../../../Fixtures/Golden/nomad-1run-me10-no-structure.ravworks.json';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8057';

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int FULLERIDES = 16679;
    private const int CARBON_POLYMERS = 16659;
    private const int PLATINUM_TECHNITE = 16662;
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int MORPHITE = 11399;
    private const int FENRIR = 20189;
    private const int CAPITAL_PROPULSION_ENGINE = 21009;
    private const int WIDOW = 22436;
    private const int TITANIUM_DIBORITE_ARMOR_PLATE = 11544;
    private const int NOMAD = 28846;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int HYDROGEN_ISOTOPES = 17889;
    private const int OXYGEN_ISOTOPES = 17887;
    private const int COOLANT = 9832;
    private const int COMPOSITE_GROUP_ID = 429;
    private const int DEPLETED_URANIUM_L = 197;
    private const int DEPLETED_URANIUM_L_BLUEPRINT = 898;
    private const int DATACORE_NUCLEAR_PHYSICS = 20423;
    private const int DATACORE_MECHANICAL_ENGINEERING = 20424;
    private const float HAIL_L_BASE_PROBABILITY = 0.34;
    private const int HAIL_L_INVENTED_BPC_RUNS = 10;
    private const int NUCLEAR_PHYSICS_PER_ATTEMPT = 1;
    private const int MECHANICAL_ENGINEERING_PER_ATTEMPT = 2;
    private const int HAIL_L_CRATE = 990_001;
    private const int DATACORE_RESEARCH_KIT = 990_002;
    private const int PROTOTYPE_AMMUNITION_L = 990_003;
    private const int SELF_REPAIRING_HULL = 990_004;
    private const int HULL_REPAIR_NANITE = 990_005;
    private const float PROTOTYPE_AMMUNITION_L_BASE_PROBABILITY = 0.4;
    private const int PROTOTYPE_AMMUNITION_L_INVENTED_BPC_RUNS = 5;
    private const int SYNTHETIC_MAX_PRODUCTION_LIMIT = 300;
    private const int SYNTHETIC_BASE_TIME_SECONDS = 1_200;
    private const int HAIL_L_MANUFACTURING_LINES_OF_THIRTY_FIVE_THOUSAND_RUNS = 3_500;

    private const array INVENTION_TYPE_NAMES = [
        self::DEPLETED_URANIUM_L => 'Depleted Uranium L',
        self::DATACORE_NUCLEAR_PHYSICS => 'Datacore - Nuclear Physics',
        self::DATACORE_MECHANICAL_ENGINEERING => 'Datacore - Mechanical Engineering',
    ];

    private const array NITROGEN_FUEL_BLOCK_ONE_RUN_ME_TEN = [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17888 => 405];

    private const array NITROGEN_FUEL_BLOCK_TWENTY_FIVE_RUNS_ME_TEN = [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, 9848 => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125];

    private const array FERNITE_CARBIDE_ONE_RUN = [
        44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18,
        16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100, 17889 => 405,
    ];

    private const array HAIL_L_ONE_RUN = [
        34 => 501, 35 => 400, 36 => 200, 37 => 74, 38 => 33, 44 => 12, 3683 => 60, 3689 => 12, 9832 => 27, 9848 => 3,
        11399 => 15, 16272 => 459, 16273 => 945, 16274 => 405, 16275 => 54, 16633 => 100, 16635 => 100,
        16636 => 200, 16639 => 100, 16642 => 100, 16644 => 100, 16649 => 100, 17888 => 405, 17889 => 405,
        self::DATACORE_NUCLEAR_PHYSICS => 1, self::DATACORE_MECHANICAL_ENGINEERING => 2,
    ];

    private array $sde;

    private array $jitaPrices = [];

    private array $pricedTypeIds = [];

    private array $maxProductionLimitOverrides = [];

    private array $productTypeIdsWithoutIndustryBlueprint = [];

    private array $typeIdsWithoutSdeName = [];

    private array $otherInventions = [];

    private bool $t1BlueprintWithoutManufacturingProduct = false;

    private array $ownerStructures = [];

    private array $manufacturingRigCategories = [];

    private InMemoryUserProductionStructures $userStructures;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private JitaMarketService $jitaMarketService;
    private GroupIndustryProjectService $service;
    private User $owner;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::SDE_RECIPES_FIXTURE), true, flags: \JSON_THROW_ON_ERROR);
        foreach (self::INVENTION_TYPE_NAMES as $typeId => $typeName) {
            $this->sde['typeNames'][(string) $typeId] = $typeName;
        }

        $this->connection = $this->createMock(Connection::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );

        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturnCallback(function (array $typeIds): array {
            $this->pricedTypeIds[] = $typeIds;
            $prices = [];
            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->jitaPrices[$typeId] ?? null;
            }

            return $prices;
        });

        $this->owner = $this->createStub(User::class);
        $this->owner->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $this->service = $this->serviceInventing(self::HAIL_L_BASE_PROBABILITY);
    }

    #[Group('issue-141')]
    public function testTwentyFiveRunsOfNitrogenFuelBlockGiveABomOfTwentyFiveRunsAndTheMaterialsOfAllOfThem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 25, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [44, 'Enriched Uranium', 90],
                [3683, 'Oxygen', 495],
                [3689, 'Mechanical Parts', 90],
                [9832, 'Coolant', 203],
                [9848, 'Robotics', 25],
                [16272, 'Heavy Water', 3825],
                [16273, 'Liquid Ozone', 7875],
                [16275, 'Strontium Clathrates', 450],
                [17888, 'Nitrogen Isotopes', 10125],
            ],
            array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getTypeId(), $bomItem->getTypeName(), $bomItem->getRequiredQuantity()],
                $this->sortedMaterialBomItems($project),
            ),
        );
        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 25, 25, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 25, 1_000, null],
            ],
            $this->jobsIgnoringMeAndTe($project),
        );
        self::assertCount(11, $project->getBomItems());
    }

    #[Group('issue-141')]
    public function testTwoHundredFiftyRunsOfNitrogenFuelBlockAreSplitIntoTwoEvenJobsUnderTheMaxProductionLimitWithMaterialsRecomputedJobByJob(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 250, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 250, 0, 0, 250, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 125, 10, 20, 5_000, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 125, 10, 20, 5_000, null],
            ],
            $this->jobs($project),
        );
        self::assertSame(
            [44 => 900, 3683 => 4950, 3689 => 900, 9832 => 2026, 9848 => 250, 16272 => 38250, 16273 => 78750, 16275 => 4500, 17888 => 101250],
            $this->materials($project),
        );
    }

    #[Group('issue-141')]
    public function testOneRunOfNomadBuysTheLeavesOfThePlanWithTheCoolantOfTheHydrogenFuelBlockSplitUnderItsMaxProductionLimit(): void
    {
        $project = $this->projectOf([[self::NOMAD, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $golden = json_decode((string) file_get_contents(self::NOMAD_GOLDEN), true, 512, \JSON_THROW_ON_ERROR);
        $expectedMaterials = [];
        foreach ($golden['expected']['leaves'] as $leaf) {
            $expectedMaterials[$leaf['typeId']] = $leaf['quantity'];
        }
        $expectedMaterials[self::COOLANT] = 4693;
        ksort($expectedMaterials);
        self::assertSame(17820, $this->materials($project)[self::OXYGEN_ISOTOPES] ?? null);
        self::assertSame($expectedMaterials, $this->materials($project));
        self::assertCount(63, $this->materialBomItems($project));
        self::assertCount(96, $this->jobBomItems($project));
        self::assertSame([161, 161], $this->runsOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing'));
    }

    #[Group('issue-141')]
    #[DataProvider('severalUnitsPerRunItemProvider')]
    public function testGroupBomOfAProductOfSeveralUnitsPerRunHasTheLeavesOfTheProductionCostCalculatorInPlanMode(int $productTypeId, int $runs, array $expectedMaterials): void
    {
        $project = $this->projectOf([[$productTypeId, $runs, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $productionCost = (new ProductionCostCalculator(
            InMemoryBlueprintCatalog::fromSdeFixture(),
            new InMemoryInventionCatalog(),
            new InMemoryMarketPrices([]),
            new InMemoryAdjustedPrices([]),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
        ))->costOf(new ProductionCostRequest(
            productTypeId: $productTypeId,
            runs: new Runs($runs),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(10), new TimeEfficiency(20)),
            mode: CostMode::Plan,
            structures: [],
            favoriteSystems: FavoriteSystems::none(),
        ));
        $calculatorLeaves = array_map(static fn (LeafCost $leaf): int => (int) $leaf->quantity, $productionCost->leaves);
        ksort($calculatorLeaves);

        self::assertSame($expectedMaterials, $calculatorLeaves);
        self::assertSame($calculatorLeaves, $this->materials($project));
    }

    public static function severalUnitsPerRunItemProvider(): iterable
    {
        yield 'twenty-five runs of Nitrogen Fuel Block, forty units per run' => [self::NITROGEN_FUEL_BLOCK, 25, self::NITROGEN_FUEL_BLOCK_TWENTY_FIVE_RUNS_ME_TEN];
        yield 'one run of Fernite Carbide, ten thousand units per run' => [self::FERNITE_CARBIDE, 1, self::FERNITE_CARBIDE_ONE_RUN];
    }

    #[Group('issue-23')]
    public function testFinalManufacturingJobCarriesTheMeAndTeOfTheProjectItem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobs($project),
        );
    }

    #[Group('issue-23')]
    public function testFinalManufacturingJobCarriesTheTeZeroOfTheProjectItem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 0, 40, null],
            ],
            $this->jobs($project),
        );
    }

    #[Group('issue-23')]
    public function testMaterialsOfTheBomFollowTheMeOfTheProjectItemAndIgnoreItsTe(): void
    {
        $projectAtTeTwenty = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);
        $projectAtTeZero = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 0]]);

        $this->service->buildBom($projectAtTeTwenty, $this->owner);
        $this->service->buildBom($projectAtTeZero, $this->owner);

        self::assertSame(self::NITROGEN_FUEL_BLOCK_ONE_RUN_ME_TEN, $this->materials($projectAtTeTwenty));
        self::assertSame($this->materials($projectAtTeTwenty), $this->materials($projectAtTeZero));
    }

    #[Group('issue-23')]
    public function testFinalManufacturingJobOfAT2ProductCarriesTheMeAndTeOfTheProjectItem(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::HAIL_L, 'manufacturing', 'final', 1, 2, 4, 5_000, null]],
            $this->jobsOfTypeAndActivity($project, self::HAIL_L, 'manufacturing'),
        );
    }

    #[Group('issue-23')]
    public function testFinalReactionJobKeepsMeZeroAndTeZero(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, 10_000, null]],
            $this->jobsOfType($project, self::FERNITE_CARBIDE),
        );
    }

    #[Group('issue-23')]
    #[Group('issue-141')]
    public function testTwoReactionItemsOfTheSameProductAtDifferentMeShareOneFinalJobAtMeZeroAndTeZero(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 1, 0, 0], [self::FERNITE_CARBIDE, 2, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::FERNITE_CARBIDE, 'reaction', 'final', 3, 0, 0, 30_000, null]],
            $this->jobsOfType($project, self::FERNITE_CARBIDE),
        );
    }

    #[Group('issue-141')]
    public function testStructureOfTheOwnerReducesTheMaterialsOfTheJobByTheBonusForItsProduct(): void
    {
        $this->ownerStructures = [
            ProductionStructures::raitaru('owner-raitaru', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition')),
        ];
        $this->manufacturingRigCategories = [self::NITROGEN_FUEL_BLOCK => 'ammunition'];
        $service = $this->serviceInventing(self::HAIL_L_BASE_PROBABILITY);
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $service->buildBom($project, $this->owner);

        self::assertSame(
            [44 => 4, 3683 => 19, 3689 => 4, 9832 => 8, 9848 => 1, 16272 => 146, 16273 => 299, 16275 => 18, 17888 => 385],
            $this->materials($project),
        );
        self::assertSame([self::OWNER_ID], $this->userStructures->requestedUserIds);
    }

    #[Group('issue-23')]
    public function testSameProductOnTwoItemsAtDifferentMeAndTeGetsOneFinalJobPerItem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::NITROGEN_FUEL_BLOCK, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 0, 0, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobs($project),
        );
    }

    #[Group('issue-23')]
    public function testSameProductOnTwoItemsAtDifferentMeAddsTheMaterialsOfEachItemAtItsOwnMe(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::NITROGEN_FUEL_BLOCK, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [44 => 8, 3683 => 42, 3689 => 8, 9832 => 18, 9848 => 2, 16272 => 323, 16273 => 665, 16275 => 38, 17888 => 855],
            $this->materials($project),
        );
    }

    #[Group('issue-23')]
    #[Group('issue-141')]
    public function testSameProductOnTwoItemsAtTheSameMeAndTeSharesOneFinalJobWhoseMaterialsAreRoundedOnceForItsSummedRuns(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 2, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 2, 80, null],
            ],
            $this->jobsIgnoringMeAndTe($project),
        );
        self::assertSame(
            [44 => 8, 3683 => 40, 3689 => 8, 9832 => 17, 9848 => 2, 16272 => 306, 16273 => 630, 16275 => 36, 17888 => 810],
            $this->materials($project),
        );
    }

    public function testSameProductOnTwoItemsAtTheSameTeButDifferentMeGetsOneFinalJobPerItem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::NITROGEN_FUEL_BLOCK, 1, 8, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 8, 20, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobs($project),
        );
    }

    public function testSameProductOnTwoItemsAtTheSameMeButDifferentTeGetsOneFinalJobPerItem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::NITROGEN_FUEL_BLOCK, 1, 10, 14]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 14, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobs($project),
        );
    }

    public function testTwoProductsOnItemsAtTheSameMeAndTeEachGetTheirOwnFinalJob(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::HYDROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobs($project),
        );
    }

    public function testProjectItemAtMeZeroKeepsTheBaseQuantitiesOfTheBlueprint(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [44 => 4, 3683 => 22, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 170, 16273 => 350, 16275 => 20, 17888 => 450],
            $this->materials($project),
        );
    }

    #[Group('issue-141')]
    public function testTenRunsOfFerniteCarbideGiveTenReactionRuns(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::FERNITE_CARBIDE, 'reaction', 'final', 10, 0, 0, 100_000, null]],
            array_values(array_filter($this->jobs($project), static fn (array $job): bool => $job[2] === 'final')),
        );
    }

    #[Group('issue-141')]
    public function testOneRunOfFerniteCarbideBuildsTheHydrogenFuelBlockOfItsThreeConsumersInOneRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(self::FERNITE_CARBIDE_ONE_RUN, $this->materials($project));
        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::FERNITE_CARBIDE],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40, self::FERNITE_CARBIDE],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, 10_000, null],
            ],
            $this->jobs($project),
        );
    }

    #[Group('issue-141')]
    public function testOneRunOfHailLBuildsEachFuelBlockOnceForAllItsConsumers(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(self::HAIL_L_ONE_RUN, $this->materials($project));
        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::FULLERIDES],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40, self::FULLERIDES],
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::FERNITE_CARBIDE],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40, self::FERNITE_CARBIDE],
                [self::HELIUM_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::CARBON_POLYMERS],
                [self::HELIUM_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40, self::CARBON_POLYMERS],
                [self::RAM_AMMUNITION_TECH, 'copy', 'blueprint', 1, 0, 0, 1, self::HAIL_L],
                [self::RAM_AMMUNITION_TECH, 'manufacturing', 'component', 1, 10, 20, 100, self::HAIL_L],
                [self::HAIL_L, 'manufacturing', 'final', 1, 2, 4, 5_000, null],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::CARBON_POLYMERS, 'reaction', 'component', 1, 0, 0, 200, self::FULLERIDES],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::PLATINUM_TECHNITE, 'reaction', 'component', 1, 0, 0, 200, self::FULLERIDES],
                [self::FERNITE_CARBIDE, 'reaction', 'component', 1, 0, 0, 10_000, self::HAIL_L],
                [self::FULLERIDES, 'reaction', 'component', 1, 0, 0, 3_000, self::HAIL_L],
            ],
            array_values(array_filter($this->jobs($project), static fn (array $job): bool => !in_array($job[0], [self::HAIL_L, self::DEPLETED_URANIUM_L], true) || $job[1] === 'manufacturing')),
        );
        self::assertSame($this->hailLInventionJobs(1), $this->inventionJobs($project));
    }

    #[Group('issue-105')]
    public function testOneManufacturingRunOfHailLIsInventedInOneAttemptFromOneCopyOfTheT1BlueprintWithItsDatacores(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame([], $this->jobsOfTypeAndActivity($project, self::HAIL_L, 'copy'));
        self::assertSame($this->hailLInventionJobs(1), $this->inventionJobs($project));
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 1], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 2]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    public function testInventionNamesTheT2ProductAndTheCopyNamesTheT1Product(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [['Hail L'], ['Depleted Uranium L']],
            [
                array_map(static fn (GroupIndustryBomItem $bomItem): string => $bomItem->getTypeName(), $this->jobsOf($project, self::HAIL_L, 'invention')),
                array_map(static fn (GroupIndustryBomItem $bomItem): string => $bomItem->getTypeName(), $this->jobsOf($project, self::DEPLETED_URANIUM_L, 'copy')),
            ],
        );
    }

    #[Group('issue-105')]
    public function testDatacoresArePricedFromJitaLikeTheOtherMaterials(): void
    {
        $this->jitaPrices = [self::DATACORE_NUCLEAR_PHYSICS => 68_500.0, self::DATACORE_MECHANICAL_ENGINEERING => 91_200.0, self::MORPHITE => 7_350.0];
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        $estimatedPrices = [];
        foreach ($this->materialBomItems($project) as $bomItem) {
            $estimatedPrices[$bomItem->getTypeId()] = $bomItem->getEstimatedPrice();
        }
        $estimatedPrices = array_intersect_key($estimatedPrices, array_flip([self::MORPHITE, self::DATACORE_NUCLEAR_PHYSICS, self::DATACORE_MECHANICAL_ENGINEERING]));
        ksort($estimatedPrices);
        self::assertSame(
            [self::MORPHITE => 7_350.0, self::DATACORE_NUCLEAR_PHYSICS => 68_500.0, self::DATACORE_MECHANICAL_ENGINEERING => 91_200.0],
            $estimatedPrices,
        );
        self::assertCount(1, $this->pricedTypeIds);
        self::assertContains(self::DATACORE_NUCLEAR_PHYSICS, $this->pricedTypeIds[0]);
        self::assertContains(self::DATACORE_MECHANICAL_ENGINEERING, $this->pricedTypeIds[0]);
    }

    #[Group('issue-105')]
    #[Group('issue-141')]
    public function testTenRunsOfHailLAreInventedForTheirTenManufacturingRuns(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 10, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[10], [3], [3]],
            [
                $this->runsOf($project, self::HAIL_L, 'manufacturing'),
                $this->runsOf($project, self::HAIL_L, 'invention'),
                $this->runsOf($project, self::DEPLETED_URANIUM_L, 'copy'),
            ],
        );
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 3], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 6]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    #[Group('issue-141')]
    public function testThirtyFiveThousandRunsOfHailLAreSplitIntoJobsOfTenRunsAndInventedForAllTheirManufacturingRuns(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 35_000, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            array_fill(0, self::HAIL_L_MANUFACTURING_LINES_OF_THIRTY_FIVE_THOUSAND_RUNS, [self::HAIL_L, 'manufacturing', 'final', 10, 2, 4, 50_000, null]),
            $this->jobsOfTypeAndActivity($project, self::HAIL_L, 'manufacturing'),
        );
        self::assertSame($this->hailLInventionJobs(10_295), $this->inventionJobs($project));
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 10_295], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 20_590]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    public function testNotInventedProductKeepsTheCopyOfItsBlueprintAndGetsNoInventionNorDatacore(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobs($project),
        );
        self::assertSame(self::NITROGEN_FUEL_BLOCK_ONE_RUN_ME_TEN, $this->materials($project));
    }

    #[Group('issue-105')]
    #[Group('issue-141')]
    public function testIntermediatesOfAnInventedProductKeepTheCopyOfTheirBlueprint(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::FULLERIDES],
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::FERNITE_CARBIDE],
                [self::HELIUM_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::CARBON_POLYMERS],
                [self::RAM_AMMUNITION_TECH, 'copy', 'blueprint', 1, 0, 0, 1, self::HAIL_L],
            ],
            array_values(array_filter(
                $this->jobs($project),
                static fn (array $job): bool => $job[1] === 'copy' && !in_array($job[0], [self::HAIL_L, self::DEPLETED_URANIUM_L], true),
            )),
        );
    }

    #[Group('issue-105')]
    public function testInventionWithoutProbabilityThrowsNamingTheT2ProductAndTheT1BlueprintAndLeavesTheBomEmpty(): void
    {
        $service = $this->serviceInventing(null);
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::HAIL_L, 1, 2, 4]]);

        $missingProbability = null;
        try {
            $service->buildBom($project, $this->owner);
        } catch (\RuntimeException $exception) {
            $missingProbability = $exception;
        }

        self::assertInstanceOf(InventionProbabilityMissingException::class, $missingProbability);
        self::assertMatchesRegularExpression('/\b'.self::HAIL_L.'\b/', $missingProbability->getMessage());
        self::assertMatchesRegularExpression('/\b'.self::DEPLETED_URANIUM_L_BLUEPRINT.'\b/', $missingProbability->getMessage());
        self::assertCount(0, $project->getBomItems());
    }

    #[Group('issue-105')]
    public function testInventionWithoutProbabilityPersistsNoProject(): void
    {
        [$failure, $persisted] = $this->inventionFailure($this->serviceInventing(null));

        self::assertInstanceOf(InventionProbabilityMissingException::class, $failure);
        self::assertSame([], $persisted);
    }

    #[Group('issue-105')]
    #[Group('issue-141')]
    public function testTwoHailLItemsOfOneAndSevenManufacturingRunsShareOneInventionAndOneT1CopySizedOnTheirSum(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::HAIL_L, 7, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[1, 2, 4], [7, 0, 0]],
            $this->sortedJobs(array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getRuns(), $bomItem->getMeLevel(), $bomItem->getTeLevel()],
                $this->jobsOf($project, self::HAIL_L, 'manufacturing'),
            )),
        );
        self::assertSame($this->hailLInventionJobs(3), $this->inventionJobs($project));
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 3], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 6]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    #[Group('issue-141')]
    public function testTwoHailLItemsOfSevenManufacturingRunsEachNeedFiveAttemptsOnTheirSum(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 7, 2, 4], [self::HAIL_L, 7, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[7, 0, 0], [7, 2, 4]],
            $this->sortedJobs(array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getRuns(), $bomItem->getMeLevel(), $bomItem->getTeLevel()],
                $this->jobsOf($project, self::HAIL_L, 'manufacturing'),
            )),
        );
        self::assertSame($this->hailLInventionJobs(5), $this->inventionJobs($project));
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 5], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 10]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    public function testManufacturingRunsExactlyCoveredByTheExpectedInventedRunsNeedNoExtraAttempt(): void
    {
        $this->addManufacturingRecipe(self::PROTOTYPE_AMMUNITION_L, 'Prototype Ammunition L', 1, [(string) self::MORPHITE => 6]);
        $this->otherInventions[self::PROTOTYPE_AMMUNITION_L] = self::inventionFromDepletedUraniumL(
            self::PROTOTYPE_AMMUNITION_L,
            0.5,
            10,
            [self::DATACORE_NUCLEAR_PHYSICS => 3, self::DATACORE_MECHANICAL_ENGINEERING => 4],
        );
        $project = $this->projectOf([[self::PROTOTYPE_AMMUNITION_L, 5, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', 1, 0, 0, 1, null],
                [self::PROTOTYPE_AMMUNITION_L, 'invention', 'blueprint', 1, 0, 0, 1, null],
                [self::PROTOTYPE_AMMUNITION_L, 'manufacturing', 'final', 5, 0, 0, 5, null],
            ],
            $this->jobs($project),
        );
        self::assertSame([[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 3], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 4]], $this->datacoreLines($project));
    }

    #[Group('issue-105')]
    public function testT1BlueprintWithoutManufacturingProductThrowsNamingTheT1BlueprintAndPersistsNoProject(): void
    {
        $this->t1BlueprintWithoutManufacturingProduct = true;

        [$failure, $persisted] = $this->inventionFailure($this->service);

        self::assertInstanceOf(InventionT1ProductMissingException::class, $failure);
        self::assertMatchesRegularExpression('/\b'.self::DEPLETED_URANIUM_L_BLUEPRINT.'\b/', $failure->getMessage());
        self::assertSame([], $persisted);
    }

    #[Group('issue-105')]
    public function testZeroInventionProbabilityThrowsAsAMissingProbabilityAndPersistsNoProject(): void
    {
        [$failure, $persisted] = $this->inventionFailure($this->serviceInventing(0.0));

        self::assertInstanceOf(InventionProbabilityMissingException::class, $failure);
        self::assertSame([], $persisted);
    }

    #[Group('issue-105')]
    public function testZeroInventedBpcRunsThrowAsAMissingProbabilityAndPersistNoProject(): void
    {
        [$failure, $persisted] = $this->inventionFailure($this->serviceInventing(self::HAIL_L_BASE_PROBABILITY, 0));

        self::assertInstanceOf(InventionProbabilityMissingException::class, $failure);
        self::assertSame([], $persisted);
    }

    #[Group('issue-105')]
    public function testInventionOfAnIntermediateHangsUnderTheParentOfItsManufacturingJob(): void
    {
        $this->addManufacturingRecipe(self::HAIL_L_CRATE, 'Hail L Crate', 1, [(string) self::HAIL_L => 22_000]);
        $project = $this->projectOf([[self::HAIL_L_CRATE, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::HAIL_L, 'manufacturing', 'component', 5, 10, 20, 25_000, self::HAIL_L_CRATE]],
            $this->jobsOfTypeAndActivity($project, self::HAIL_L, 'manufacturing'),
        );
        self::assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', 2, 0, 0, 2, self::HAIL_L_CRATE],
                [self::HAIL_L, 'invention', 'blueprint', 2, 0, 0, 2, self::HAIL_L_CRATE],
            ],
            $this->inventionJobs($project),
        );
    }

    #[Group('issue-105')]
    public function testDatacoreAlsoNeededAsAMaterialOfAnotherTreeAddsBothQuantities(): void
    {
        $this->addManufacturingRecipe(self::DATACORE_RESEARCH_KIT, 'Datacore Research Kit', 1, [(string) self::DATACORE_NUCLEAR_PHYSICS => 4, (string) self::MORPHITE => 9]);
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::DATACORE_RESEARCH_KIT, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 5], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 2]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    public function testTwoT2ProductsInventedFromTheSameT1BlueprintShareOneT1CopyAndAddTheirAttemptsAndDatacores(): void
    {
        $this->addManufacturingRecipe(self::PROTOTYPE_AMMUNITION_L, 'Prototype Ammunition L', 1, [(string) self::MORPHITE => 6]);
        $this->otherInventions[self::PROTOTYPE_AMMUNITION_L] = self::inventionFromDepletedUraniumL(
            self::PROTOTYPE_AMMUNITION_L,
            self::PROTOTYPE_AMMUNITION_L_BASE_PROBABILITY,
            self::PROTOTYPE_AMMUNITION_L_INVENTED_BPC_RUNS,
            [self::DATACORE_NUCLEAR_PHYSICS => 3, self::DATACORE_MECHANICAL_ENGINEERING => 4],
        );
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::PROTOTYPE_AMMUNITION_L, 3, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', 3, 0, 0, 3, null],
                [self::HAIL_L, 'invention', 'blueprint', 1, 0, 0, 1, null],
                [self::PROTOTYPE_AMMUNITION_L, 'invention', 'blueprint', 2, 0, 0, 2, null],
            ],
            $this->inventionJobs($project),
        );
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 7], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 10]],
            $this->datacoreLines($project),
        );
    }

    #[Group('issue-105')]
    public function testDatacoreMissingFromTheSdeTypesThrowsAndLeavesTheBomEmpty(): void
    {
        $this->typeIdsWithoutSdeName = [self::DATACORE_MECHANICAL_ENGINEERING];
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        try {
            $this->service->buildBom($project, $this->owner);
            self::fail('Expected a TypeMissingFromSdeException');
        } catch (TypeMissingFromSdeException $exception) {
            self::assertSame([self::DATACORE_MECHANICAL_ENGINEERING], $exception->getMissingTypeIds());
        }
        self::assertCount(0, $project->getBomItems());
    }

    #[Group('issue-105')]
    public function testT1ProductMissingFromTheSdeTypesThrowsAndLeavesTheBomEmpty(): void
    {
        $this->typeIdsWithoutSdeName = [self::DEPLETED_URANIUM_L];
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        try {
            $this->service->buildBom($project, $this->owner);
            self::fail('Expected a TypeMissingFromSdeException');
        } catch (TypeMissingFromSdeException $exception) {
            self::assertSame([self::DEPLETED_URANIUM_L], $exception->getMissingTypeIds());
        }
        self::assertCount(0, $project->getBomItems());
    }

    #[Group('issue-106')]
    #[Group('issue-141')]
    public function testProjectItemAlsoNeededAsAnIntermediateKeepsItsFinalJobNextToTheIntermediateJobAndBuildsTheSharedFuelBlockOnce(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::FERNITE_CARBIDE, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::FERNITE_CARBIDE, 'reaction', 'component', 1, 0, 0, 10_000, self::HAIL_L],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, 10_000, null],
            ],
            $this->jobsOfType($project, self::FERNITE_CARBIDE),
        );
        self::assertSame([self::HAIL_L, self::FERNITE_CARBIDE], $this->finalJobTypeIds($project));
        self::assertSame(
            [[1], [1], [1]],
            [
                $this->runsOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing'),
                $this->runsOf($project, self::HYDROGEN_FUEL_BLOCK, 'copy'),
                $this->runsOf($project, self::FERNITE_ALLOY, 'reaction'),
            ],
        );
        self::assertSame(405, $this->materials($project)[self::HYDROGEN_ISOTOPES] ?? null);
    }

    #[Group('issue-106')]
    public function testOrderOfTheProjectItemsLeavesTheJobsAndMaterialsOfTheBomUnchanged(): void
    {
        $intermediateFirstProject = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::FERNITE_CARBIDE, 1, 0, 0]]);
        $projectItemFirstProject = $this->projectOf([[self::FERNITE_CARBIDE, 1, 0, 0], [self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($intermediateFirstProject, $this->owner);
        $this->service->buildBom($projectItemFirstProject, $this->owner);

        self::assertCount(18, $this->jobs($intermediateFirstProject));
        self::assertSame($this->jobs($intermediateFirstProject), $this->jobs($projectItemFirstProject));
        self::assertSame($this->materials($intermediateFirstProject), $this->materials($projectItemFirstProject));
    }

    #[Group('issue-106')]
    #[Group('issue-23')]
    #[Group('issue-141')]
    public function testManufacturedProjectItemAlsoNeededAsAnIntermediateKeepsItsFinalJobNextToTheIntermediateJob(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40, self::FULLERIDES],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->jobsOfTypeAndActivity($project, self::NITROGEN_FUEL_BLOCK, 'manufacturing'),
        );
        self::assertSame([[self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2, null]], $this->jobsOfTypeAndActivity($project, self::NITROGEN_FUEL_BLOCK, 'copy'));
    }

    #[Group('issue-106')]
    #[Group('issue-105')]
    #[Group('issue-141')]
    public function testProjectItemAlsoNeededAsAnIntermediateBuysTheMaterialsOfBothJobsOnTheirAggregatedDemand(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::FERNITE_CARBIDE, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(self::HAIL_L_ONE_RUN, $this->materials($project));
    }

    public function testBlacklistedIntermediateBecomesAMaterialOfTheBom(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);
        $project->setBlacklistTypeIds([self::FERNITE_CARBIDE]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(2940, $this->materials($project)[self::FERNITE_CARBIDE] ?? null);
        self::assertSame([], $this->jobsOf($project, self::FERNITE_CARBIDE, 'reaction'));
        self::assertSame([], $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing'));
    }

    public function testBlacklistedGroupIsResolvedToItsPublishedTypesFromTheSde(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([['type_id' => (string) self::FULLERIDES]]);
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);
        $project->setBlacklistGroupIds([self::COMPOSITE_GROUP_ID]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(1176, $this->materials($project)[self::FULLERIDES] ?? null);
        self::assertSame([], $this->jobsOf($project, self::FULLERIDES, 'reaction'));
        self::assertSame([], $this->jobsOf($project, self::NITROGEN_FUEL_BLOCK, 'manufacturing'));
    }

    #[Group('issue-8')]
    public function testMaterialsTakeTheirEstimatedPriceFromJitaAndKeepNullWithoutAPrice(): void
    {
        $this->jitaPrices = [17888 => 512.5, 9848 => 76_000.0];
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $estimatedPrices = [];
        foreach ($this->materialBomItems($project) as $bomItem) {
            $estimatedPrices[$bomItem->getTypeId()] = $bomItem->getEstimatedPrice();
        }
        ksort($estimatedPrices);
        self::assertSame(
            [44 => null, 3683 => null, 3689 => null, 9832 => null, 9848 => 76_000.0, 16272 => null, 16273 => null, 16275 => null, 17888 => 512.5],
            $estimatedPrices,
        );
        self::assertCount(1, $this->pricedTypeIds);
        $pricedTypeIds = $this->pricedTypeIds[0];
        sort($pricedTypeIds);
        self::assertSame([44, 3683, 3689, 9832, 9848, 16272, 16273, 16275, 17888], $pricedTypeIds);
    }

    #[Group('issue-156')]
    public function testJobLinesCurrentlyHaveNoEstimatedPriceIssue156(): void
    {
        $this->jitaPrices = [17888 => 512.5, 9848 => 76_000.0, self::NITROGEN_FUEL_BLOCK => 18_250.0];
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK, 'copy', null], [self::NITROGEN_FUEL_BLOCK, 'manufacturing', null]],
            $this->sortedJobs(array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getTypeId(), (string) $bomItem->getActivityType(), $bomItem->getEstimatedPrice()],
                $this->jobBomItems($project),
            )),
        );
    }

    #[Group('issue-141')]
    public function testJobAboveTheMaxProductionLimitOfItsBlueprintIsSplitEvenlyButNotItsCopy(): void
    {
        $this->maxProductionLimitOverrides = [self::CAPITAL_PROPULSION_ENGINE => 15];
        $project = $this->projectOf([[self::FENRIR, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::CAPITAL_PROPULSION_ENGINE, 'copy', 'blueprint', 20, 0, 0, 20, self::FENRIR],
                [self::CAPITAL_PROPULSION_ENGINE, 'manufacturing', 'component', 10, 10, 20, 10, self::FENRIR],
                [self::CAPITAL_PROPULSION_ENGINE, 'manufacturing', 'component', 10, 10, 20, 10, self::FENRIR],
            ],
            $this->jobsOfType($project, self::CAPITAL_PROPULSION_ENGINE),
        );
    }

    public function testJobAtTheMaxProductionLimitOfItsBlueprintIsNotSplit(): void
    {
        $this->maxProductionLimitOverrides = [self::CAPITAL_PROPULSION_ENGINE => 20];
        $project = $this->projectOf([[self::FENRIR, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::CAPITAL_PROPULSION_ENGINE, 'copy', 'blueprint', 20, 0, 0, 20, self::FENRIR],
                [self::CAPITAL_PROPULSION_ENGINE, 'manufacturing', 'component', 20, 10, 20, 20, self::FENRIR],
            ],
            $this->jobsOfType($project, self::CAPITAL_PROPULSION_ENGINE),
        );
    }

    #[Group('issue-160')]
    #[Group('issue-141')]
    public function testManufacturingJobUnderTheMaxProductionLimitOfItsBlueprintInTheSdeIsNotSplit(): void
    {
        $project = $this->projectOf([[self::WIDOW, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::TITANIUM_DIBORITE_ARMOR_PLATE, 'copy', 'blueprint', 18_750, 0, 0, 18_750, self::WIDOW],
                [self::TITANIUM_DIBORITE_ARMOR_PLATE, 'manufacturing', 'component', 18_750, 10, 20, 18_750, self::WIDOW],
            ],
            $this->jobsOfType($project, self::TITANIUM_DIBORITE_ARMOR_PLATE),
        );
    }

    #[Group('issue-160')]
    #[Group('issue-141')]
    public function testReactionAboveTheMaxProductionLimitOfItsFormulaInTheSdeIsSplitEvenly(): void
    {
        $project = $this->projectOf([[self::FERNITE_ALLOY, 2_501, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::FERNITE_ALLOY, 'reaction', 'final', 833, 0, 0, 166_600, null],
                [self::FERNITE_ALLOY, 'reaction', 'final', 834, 0, 0, 166_800, null],
                [self::FERNITE_ALLOY, 'reaction', 'final', 834, 0, 0, 166_800, null],
            ],
            $this->jobsOfType($project, self::FERNITE_ALLOY),
        );
    }

    #[Group('issue-160')]
    #[Group('issue-141')]
    public function testBlueprintWithoutMaxProductionLimitInTheSdeThrowsNamingItAndLeavesTheBomEmpty(): void
    {
        $this->productTypeIdsWithoutIndustryBlueprint = [self::NITROGEN_FUEL_BLOCK];
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $failure = null;
        try {
            $this->service->buildBom($project, $this->owner);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertMatchesRegularExpression('/\b'.(self::NITROGEN_FUEL_BLOCK + self::BLUEPRINT_TYPE_ID_OFFSET).'\b/', $failure->getMessage());
        self::assertCount(0, $project->getBomItems());
    }

    #[Group('issue-141')]
    public function testProductWithoutBlueprintNorReactionThrowsARuntimeExceptionNamingItAndLeavesTheBomEmpty(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::MORPHITE, 1, 0, 0]]);

        $failure = null;
        try {
            $this->service->buildBom($project, $this->owner);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('No blueprint or reaction found for product type ID '.self::MORPHITE, $failure->getMessage());
        self::assertCount(0, $project->getBomItems());
    }

    #[Group('issue-141')]
    public function testCycleInTheProductionGraphThrowsARuntimeExceptionNamingTheProductNeededToBuildItselfAndLeavesTheBomEmpty(): void
    {
        $this->addManufacturingRecipe(self::SELF_REPAIRING_HULL, 'Self-Repairing Hull', 1, [(string) self::HULL_REPAIR_NANITE => 2]);
        $this->addManufacturingRecipe(self::HULL_REPAIR_NANITE, 'Hull Repair Nanite', 4, [(string) self::SELF_REPAIRING_HULL => 3, (string) self::MORPHITE => 4]);
        $project = $this->projectOf([[self::SELF_REPAIRING_HULL, 1, 0, 0]]);

        $failure = null;
        try {
            $this->service->buildBom($project, $this->owner);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertMatchesRegularExpression('/\b'.self::HULL_REPAIR_NANITE.'\b/', $failure->getMessage());
        self::assertCount(0, $project->getBomItems());
    }

    public function testTypesMissingFromTheSdeTypesThrowAndLeaveTheBomEmpty(): void
    {
        $this->typeIdsWithoutSdeName = [9848, self::NITROGEN_FUEL_BLOCK];
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        try {
            $this->service->buildBom($project, $this->owner);
            self::fail('Expected a TypeMissingFromSdeException');
        } catch (TypeMissingFromSdeException $exception) {
            self::assertSame([self::NITROGEN_FUEL_BLOCK, 9848], $exception->getMissingTypeIds());
        }
        self::assertCount(0, $project->getBomItems());
    }

    public function testCreateProjectPersistsTheProjectWithItsBomAndFlushesOnce(): void
    {
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $this->entityManager->expects(self::once())->method('flush');
        $this->connection->method('fetchAllAssociative')->willReturn([['type_id' => (string) self::FULLERIDES]]);

        $project = $this->service->createProject($this->owner, new CreateProjectData(
            name: 'Fuel run',
            items: [
                ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 1],
                ['typeId' => self::HAIL_L, 'typeName' => 'Hail L', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 1],
            ],
            blacklistGroupIds: [self::COMPOSITE_GROUP_ID],
            blacklistTypeIds: [self::FERNITE_CARBIDE],
            containerName: 'Fuel hangar',
            lineRentalRatesOverride: ['manufacturing' => 5],
            brokerFeePercent: 1.5,
            salesTaxPercent: 4.5,
        ));

        self::assertSame([$project], $persisted);
        self::assertSame(
            [$this->owner, 'Fuel run', 'Fuel hangar', ['manufacturing' => 5], [self::COMPOSITE_GROUP_ID], [self::FERNITE_CARBIDE], 1.5, 4.5],
            [
                $project->getOwner(),
                $project->getName(),
                $project->getContainerName(),
                $project->getLineRentalRatesOverride(),
                $project->getBlacklistGroupIds(),
                $project->getBlacklistTypeIds(),
                $project->getBrokerFeePercent(),
                $project->getSalesTaxPercent(),
            ],
        );
        self::assertSame(
            [[$this->owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted]],
            array_map(
                static fn (GroupIndustryProjectMember $member): array => [$member->getUser(), $member->getRole(), $member->getStatus()],
                $project->getMembers()->toArray(),
            ),
        );
        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 10, 20, 1, 0], [self::HAIL_L, 'Hail L', 2, 4, 1, 1]],
            array_map(
                static fn (GroupIndustryProjectItem $item): array => [
                    $item->getTypeId(), $item->getTypeName(), $item->getMeLevel(), $item->getTeLevel(), $item->getRuns(), $item->getSortOrder(),
                ],
                $project->getItems()->toArray(),
            ),
        );
        $materials = $this->materials($project);
        self::assertSame(
            [405, 2940, 1176],
            [$materials[self::NITROGEN_ISOTOPES] ?? null, $materials[self::FERNITE_CARBIDE] ?? null, $materials[self::FULLERIDES] ?? null],
        );
    }

    public function testCreateProjectWithAnEmptyNameLeavesTheProjectUnnamed(): void
    {
        $project = $this->service->createProject($this->owner, new CreateProjectData(
            name: '',
            items: [['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 1]],
        ));

        self::assertNull($project->getName());
    }

    public function testCreateProjectKeepsTheRunsSavedOnTheProjectItem(): void
    {
        $project = $this->service->createProject($this->owner, new CreateProjectData(
            name: 'Fuel run',
            items: [['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 25]],
        ));

        self::assertSame([25], array_map(
            static fn (GroupIndustryProjectItem $item): int => $item->getRuns(),
            $project->getItems()->toArray(),
        ));
    }

    #[Group('issue-253')]
    public function testRebuildingTheBomCurrentlyAppendsASecondFullBomNextToTheContributedLinesIssue253(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 0, 0]]);
        $this->service->buildBom($project, $this->owner);
        self::assertCount(11, $project->getBomItems());
        $isotopes = $this->bomItemsOf($project, self::NITROGEN_ISOTOPES)[0];
        $finalJob = $this->jobsOf($project, self::NITROGEN_FUEL_BLOCK, 'manufacturing')[0];
        $member = new GroupIndustryProjectMember();
        $approvedIsotopes = $this->contribution($project, $member, $isotopes, ContributionType::Material, 300, ContributionStatus::Approved);
        $isotopes->setFulfilledQuantity(300);
        $pendingJob = $this->contribution($project, $member, $finalJob, ContributionType::JobInstall, 1, ContributionStatus::Pending);

        $this->service->buildBom($project, $this->owner);

        self::assertCount(22, $project->getBomItems());
        self::assertSame([450, 450], array_map(static fn (GroupIndustryBomItem $bomItem): int => $bomItem->getRequiredQuantity(), $this->bomItemsOf($project, self::NITROGEN_ISOTOPES)));
        self::assertSame($isotopes, $this->bomItemsOf($project, self::NITROGEN_ISOTOPES)[0]);
        self::assertSame([300, 0], array_map(static fn (GroupIndustryBomItem $bomItem): int => $bomItem->getFulfilledQuantity(), $this->bomItemsOf($project, self::NITROGEN_ISOTOPES)));
        self::assertSame($isotopes, $approvedIsotopes->getBomItem());
        self::assertSame(ContributionStatus::Approved, $approvedIsotopes->getStatus());
        self::assertSame(300, $approvedIsotopes->getQuantity());
        self::assertSame($finalJob, $pendingJob->getBomItem());
        self::assertSame(ContributionStatus::Pending, $pendingJob->getStatus());
        self::assertSame([$approvedIsotopes, $pendingJob], $project->getContributions()->toArray());
    }

    #[Group('issue-107')]
    public function testFinalManufacturingJobOfOneRunRequiresTheUnitsProducedByThatRun(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame([[1, 40]], $this->runsAndRequiredQuantitiesOf($project, self::NITROGEN_FUEL_BLOCK, 'manufacturing'));
    }

    #[Group('issue-107')]
    public function testIntermediateManufacturingJobRequiresItsRunsTimesTheUnitsPerRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 3, 10, 20, 120, self::FERNITE_CARBIDE]],
            $this->jobsOfTypeAndActivity($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing'),
        );
    }

    #[Group('issue-107')]
    #[Group('issue-141')]
    public function testFinalReactionJobRequiresItsRunsTimesTheUnitsPerRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::FERNITE_CARBIDE, 'reaction', 'final', 10, 0, 0, 100_000, null]],
            $this->jobsOfTypeAndActivity($project, self::FERNITE_CARBIDE, 'reaction'),
        );
    }

    #[Group('issue-107')]
    #[Group('issue-141')]
    public function testEachLineOfASplitReactionJobRequiresItsOwnRunsTimesTheUnitsPerRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_ALLOY, 2_501, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame([[833, 166_600], [834, 166_800], [834, 166_800]], $this->runsAndRequiredQuantitiesOf($project, self::FERNITE_ALLOY, 'reaction'));
    }

    #[Group('issue-107')]
    #[Group('issue-141')]
    public function testEachLineOfASplitJobOfOneUnitPerRunRequiresItsOwnRuns(): void
    {
        $this->maxProductionLimitOverrides = [self::CAPITAL_PROPULSION_ENGINE => 15];
        $project = $this->projectOf([[self::FENRIR, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame([[10, 10], [10, 10]], $this->runsAndRequiredQuantitiesOf($project, self::CAPITAL_PROPULSION_ENGINE, 'manufacturing'));
    }

    #[Group('issue-107')]
    public function testCopyJobRequiresItsRunsAndNotUnits(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 3, 0, 0, 3, self::FERNITE_CARBIDE]],
            $this->jobsOfTypeAndActivity($project, self::HYDROGEN_FUEL_BLOCK, 'copy'),
        );
    }

    private function projectOf(array $items): GroupIndustryProject
    {
        $project = new GroupIndustryProject();
        foreach ($items as $index => [$typeId, $runs, $me, $te]) {
            $project->addItem((new GroupIndustryProjectItem())
                ->setTypeId($typeId)
                ->setTypeName($this->sde['typeNames'][(string) $typeId])
                ->setRuns($runs)
                ->setMeLevel($me)
                ->setTeLevel($te)
                ->setSortOrder($index));
        }

        return $project;
    }

    private function materials(GroupIndustryProject $project): array
    {
        $materials = [];
        foreach ($this->materialBomItems($project) as $bomItem) {
            $materials[$bomItem->getTypeId()] = $bomItem->getRequiredQuantity();
        }
        ksort($materials);

        return $materials;
    }

    private function jobs(GroupIndustryProject $project): array
    {
        return $this->sortedJobs(array_map(static fn (GroupIndustryBomItem $bomItem): array => [
            $bomItem->getTypeId(),
            (string) $bomItem->getActivityType(),
            (string) $bomItem->getJobGroup(),
            $bomItem->getRuns(),
            $bomItem->getMeLevel(),
            $bomItem->getTeLevel(),
            $bomItem->getRequiredQuantity(),
            $bomItem->getParentTypeId(),
        ], $this->jobBomItems($project)));
    }

    private function jobsIgnoringMeAndTe(GroupIndustryProject $project): array
    {
        return array_map(
            static fn (array $job): array => [$job[0], $job[1], $job[2], $job[3], $job[6], $job[7]],
            $this->jobs($project),
        );
    }

    private function jobsOfType(GroupIndustryProject $project, int $typeId): array
    {
        return array_values(array_filter($this->jobs($project), static fn (array $job): bool => $job[0] === $typeId));
    }

    private function jobsOfTypeAndActivity(GroupIndustryProject $project, int $typeId, string $activityType): array
    {
        return array_values(array_filter($this->jobsOfType($project, $typeId), static fn (array $job): bool => $job[1] === $activityType));
    }

    private function runsOf(GroupIndustryProject $project, int $typeId, string $activityType): array
    {
        return array_map(static fn (array $job): ?int => $job[3], $this->jobsOfTypeAndActivity($project, $typeId, $activityType));
    }

    private function runsAndRequiredQuantitiesOf(GroupIndustryProject $project, int $typeId, string $activityType): array
    {
        return array_map(static fn (array $job): array => [$job[3], $job[6]], $this->jobsOfTypeAndActivity($project, $typeId, $activityType));
    }

    private function sortedJobs(array $jobs): array
    {
        usort($jobs, static fn (array $left, array $right): int => $left <=> $right);

        return $jobs;
    }

    private function finalJobTypeIds(GroupIndustryProject $project): array
    {
        $finalJobTypeIds = array_values(array_map(
            static fn (GroupIndustryBomItem $bomItem): int => $bomItem->getTypeId(),
            array_filter($this->jobBomItems($project), static fn (GroupIndustryBomItem $bomItem): bool => $bomItem->getJobGroup() === 'final'),
        ));
        sort($finalJobTypeIds);

        return $finalJobTypeIds;
    }

    private function materialBomItems(GroupIndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (GroupIndustryBomItem $bomItem): bool => !$bomItem->isJob()));
    }

    private function sortedMaterialBomItems(GroupIndustryProject $project): array
    {
        $materialBomItems = $this->materialBomItems($project);
        usort($materialBomItems, static fn (GroupIndustryBomItem $left, GroupIndustryBomItem $right): int => $left->getTypeId() <=> $right->getTypeId());

        return $materialBomItems;
    }

    private function jobBomItems(GroupIndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (GroupIndustryBomItem $bomItem): bool => $bomItem->isJob()));
    }

    private function jobsOf(GroupIndustryProject $project, int $typeId, string $activityType): array
    {
        return array_values(array_filter(
            $this->jobBomItems($project),
            static fn (GroupIndustryBomItem $bomItem): bool => $bomItem->getTypeId() === $typeId && $bomItem->getActivityType() === $activityType,
        ));
    }

    private function hailLInventionJobs(int $attempts): array
    {
        return [
            [self::DEPLETED_URANIUM_L, 'copy', 'blueprint', $attempts, 0, 0, $attempts, null],
            [self::HAIL_L, 'invention', 'blueprint', $attempts, 0, 0, $attempts, null],
        ];
    }

    private function inventionJobs(GroupIndustryProject $project): array
    {
        return array_values(array_filter(
            $this->jobs($project),
            static fn (array $job): bool => $job[1] === 'invention' || ($job[1] === 'copy' && in_array($job[0], [self::HAIL_L, self::DEPLETED_URANIUM_L], true)),
        ));
    }

    private function datacoreLines(GroupIndustryProject $project): array
    {
        return array_map(
            static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getTypeId(), $bomItem->getTypeName(), $bomItem->getRequiredQuantity()],
            [
                ...$this->materialBomItemsOf($project, self::DATACORE_NUCLEAR_PHYSICS),
                ...$this->materialBomItemsOf($project, self::DATACORE_MECHANICAL_ENGINEERING),
            ],
        );
    }

    private function materialBomItemsOf(GroupIndustryProject $project, int $typeId): array
    {
        return array_values(array_filter($this->materialBomItems($project), static fn (GroupIndustryBomItem $bomItem): bool => $bomItem->getTypeId() === $typeId));
    }

    private function bomItemsOf(GroupIndustryProject $project, int $typeId): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (GroupIndustryBomItem $bomItem): bool => $bomItem->getTypeId() === $typeId));
    }

    private function contribution(
        GroupIndustryProject $project,
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        ContributionType $type,
        int $quantity,
        ContributionStatus $status,
    ): GroupIndustryContribution {
        $contribution = (new GroupIndustryContribution())
            ->setMember($member)
            ->setBomItem($bomItem)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(0.0)
            ->setStatus($status);
        $project->addContribution($contribution);

        return $contribution;
    }

    private function serviceInventing(?float $hailLBaseProbability, int $hailLInventedBpcRuns = self::HAIL_L_INVENTED_BPC_RUNS): GroupIndustryProjectService
    {
        $hailLInvention = self::inventionFromDepletedUraniumL(
            self::HAIL_L,
            $hailLBaseProbability,
            $hailLInventedBpcRuns,
            [self::DATACORE_NUCLEAR_PHYSICS => self::NUCLEAR_PHYSICS_PER_ATTEMPT, self::DATACORE_MECHANICAL_ENGINEERING => self::MECHANICAL_ENGINEERING_PER_ATTEMPT],
        );
        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('getInventionData')->willReturnCallback(
            fn (int $t2TypeId): ?array => $t2TypeId === self::HAIL_L ? $hailLInvention : ($this->otherInventions[$t2TypeId] ?? null),
        );

        $productRepository = $this->productRepositoryOnGoldenSde();
        $activityRepository = $this->activityRepositoryOnGoldenSde();
        $this->userStructures = new InMemoryUserProductionStructures([self::OWNER_ID => $this->ownerStructures]);

        return new GroupIndustryProjectService(
            new ProductionPlanning(
                new SdeBlueprintCatalog($productRepository, $activityRepository, $this->materialRepositoryOnGoldenSde(), $this->blueprintRepositoryOnGoldenSde()),
                InMemorySystemCostIndices::none(),
                new InMemoryRigCategories(manufacturing: $this->manufacturingRigCategories),
            ),
            $this->userStructures,
            $this->jitaMarketService,
            $this->entityManager,
            $inventionService,
            $productRepository,
            $activityRepository,
        );
    }

    private function productRepositoryOnGoldenSde(): IndustryActivityProductRepository
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct {
                $recipe = $this->sde['recipes'][(string) $productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return self::activityProduct($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET, $activityId, $productTypeId, $recipe['outputPerRun']);
            },
        );
        $productRepository->method('findProductsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $products = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    if ($blueprintTypeId === self::DEPLETED_URANIUM_L_BLUEPRINT) {
                        if (!$this->t1BlueprintWithoutManufacturingProduct && in_array(IndustryActivityType::Manufacturing->value, $activityIds, true)) {
                            $products[$blueprintTypeId.'-'.IndustryActivityType::Manufacturing->value] = self::activityProduct($blueprintTypeId, IndustryActivityType::Manufacturing->value, self::DEPLETED_URANIUM_L, 1);
                        }
                        continue;
                    }
                    $productTypeId = $blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET;
                    $recipe = $this->sde['recipes'][(string) $productTypeId] ?? null;
                    if ($recipe !== null && in_array(self::activityIdOf($recipe['activity']), $activityIds, true)) {
                        $activityId = self::activityIdOf($recipe['activity']);
                        $products[$blueprintTypeId.'-'.$activityId] = self::activityProduct($blueprintTypeId, $activityId, $productTypeId, $recipe['outputPerRun']);
                    }
                }

                return $products;
            },
        );

        return $productRepository;
    }

    private function materialRepositoryOnGoldenSde(): IndustryActivityMaterialRepository
    {
        $materialsOf = function (int $blueprintTypeId, int $activityId): array {
            $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
            if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                return [];
            }
            $materials = [];
            foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                $materials[] = (new IndustryActivityMaterial())
                    ->setTypeId($blueprintTypeId)
                    ->setActivityId($activityId)
                    ->setMaterialTypeId((int) $materialTypeId)
                    ->setQuantity($baseQuantityPerRun);
            }

            return $materials;
        };

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findByBlueprintAndActivity')->willReturnCallback(
            static fn (int $blueprintTypeId, int $activityId = 1): array => $materialsOf($blueprintTypeId, $activityId),
        );
        $materialRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => $materialsOf((int) $criteria['typeId'], (int) $criteria['activityId']),
        );

        return $materialRepository;
    }

    private function blueprintRepositoryOnGoldenSde(): IndustryBlueprintRepository
    {
        $blueprintOf = function (int $blueprintTypeId): ?IndustryBlueprint {
            $productTypeId = $blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET;
            $recipe = $this->sde['recipes'][(string) $productTypeId] ?? null;
            if ($recipe === null || in_array($productTypeId, $this->productTypeIdsWithoutIndustryBlueprint, true)) {
                return null;
            }

            return (new IndustryBlueprint())
                ->setTypeId($blueprintTypeId)
                ->setMaxProductionLimit($this->maxProductionLimitOverrides[$productTypeId] ?? $recipe['maxProductionLimit']);
        };

        $blueprintRepository = $this->createStub(IndustryBlueprintRepository::class);
        $blueprintRepository->method('find')->willReturnCallback(
            static fn (mixed $blueprintTypeId): ?IndustryBlueprint => \is_int($blueprintTypeId) ? $blueprintOf($blueprintTypeId) : null,
        );
        $blueprintRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($blueprintOf, (array) ($criteria['typeId'] ?? [])))),
        );

        return $blueprintRepository;
    }

    private function activityRepositoryOnGoldenSde(): IndustryActivityRepository
    {
        $activityOf = function (int $blueprintTypeId, int $activityId): ?IndustryActivity {
            $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
            if ($recipe === null) {
                return null;
            }
            $hasActivity = $activityId === IndustryActivityType::Copying->value
                ? $recipe['hasCopyActivity']
                : self::activityIdOf($recipe['activity']) === $activityId;

            return $hasActivity
                ? (new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($activityId)->setTime($recipe['baseTimeSeconds'])
                : null;
        };

        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivity => $activityOf((int) $criteria['typeId'], (int) $criteria['activityId']),
        );
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($activityOf): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($activityIds as $activityId) {
                        $activity = $activityOf($blueprintTypeId, $activityId);
                        if ($activity !== null) {
                            $activities[$blueprintTypeId.'-'.$activityId] = $activity;
                        }
                    }
                }

                return $activities;
            },
        );

        return $activityRepository;
    }

    private function typeRepositoryOnGoldenSde(): InvTypeRepository
    {
        $typeOf = fn (int $typeId): ?InvType => !in_array($typeId, $this->typeIdsWithoutSdeName, true) && isset($this->sde['typeNames'][(string) $typeId])
            ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
            : null;

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback($typeOf);
        $typeRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($typeOf, (array) $criteria['typeId']))),
        );

        return $typeRepository;
    }

    private static function inventionFromDepletedUraniumL(int $t2TypeId, ?float $baseProbability, int $inventedBpcRuns, array $datacoreQuantitiesPerAttempt): array
    {
        return [
            't1BlueprintTypeId' => self::DEPLETED_URANIUM_L_BLUEPRINT,
            't2BlueprintTypeId' => $t2TypeId + self::BLUEPRINT_TYPE_ID_OFFSET,
            'probability' => $baseProbability,
            'baseRuns' => $inventedBpcRuns,
            'materials' => array_map(
                static fn (int $datacoreTypeId, int $quantityPerAttempt): array => [
                    'typeId' => $datacoreTypeId,
                    'typeName' => self::INVENTION_TYPE_NAMES[$datacoreTypeId],
                    'quantity' => $quantityPerAttempt,
                ],
                array_keys($datacoreQuantitiesPerAttempt),
                $datacoreQuantitiesPerAttempt,
            ),
        ];
    }

    private function addManufacturingRecipe(int $productTypeId, string $typeName, int $outputPerRun, array $materials): void
    {
        $this->sde['recipes'][(string) $productTypeId] = [
            'name' => $typeName,
            'activity' => 'manufacturing',
            'outputPerRun' => $outputPerRun,
            'maxProductionLimit' => self::SYNTHETIC_MAX_PRODUCTION_LIMIT,
            'hasCopyActivity' => true,
            'baseTimeSeconds' => self::SYNTHETIC_BASE_TIME_SECONDS,
            'materials' => $materials,
        ];
        $this->sde['typeNames'][(string) $productTypeId] = $typeName;
    }

    private function inventionFailure(GroupIndustryProjectService $service): array
    {
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $failure = null;
        try {
            $service->createProject($this->owner, new CreateProjectData(
                name: 'Hail run',
                items: [['typeId' => self::HAIL_L, 'typeName' => 'Hail L', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 1]],
            ));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        return [$failure, $persisted];
    }

    private static function activityProduct(int $blueprintTypeId, int $activityId, int $productTypeId, int $outputPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($outputPerRun);
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
