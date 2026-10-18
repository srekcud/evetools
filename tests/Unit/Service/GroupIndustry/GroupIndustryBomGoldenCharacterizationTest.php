<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
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
use App\Exception\TypeMissingFromSdeException;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the group BOM written by buildBom() and createProject(), before buildProductionTree() is
 * replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 6).
 *
 * The real IndustryTreeService runs on the SDE extract of the goldens (tests/Fixtures/Golden/sde-blueprint-recipes.json);
 * only the SDE repositories, the structure lookup (no structure configured), the copy activity lookup (every
 * manufacturing blueprint can be copied, no reaction formula can), the blueprint limits and the Jita prices are stubbed.
 * Persistence and regeneration against the database: tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php.
 */
#[CoversClass(GroupIndustryProjectService::class)]
#[AllowMockObjectsWithoutExpectations]
final class GroupIndustryBomGoldenCharacterizationTest extends TestCase
{
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../Fixtures/Golden/sde-blueprint-recipes.json';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

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
    private const int NITROGEN_ISOTOPES = 17888;
    private const int COMPOSITE_GROUP_ID = 429;
    private const int DEPLETED_URANIUM_L = 197;
    private const int DEPLETED_URANIUM_L_BLUEPRINT = 898;
    private const int DATACORE_NUCLEAR_PHYSICS = 20423;
    private const int DATACORE_MECHANICAL_ENGINEERING = 20424;
    private const float HAIL_L_BASE_PROBABILITY = 0.34;
    private const int HAIL_L_INVENTED_BPC_RUNS = 10;
    private const int NUCLEAR_PHYSICS_PER_ATTEMPT = 1;
    private const int MECHANICAL_ENGINEERING_PER_ATTEMPT = 2;

    private const array INVENTION_TYPE_NAMES = [
        self::DEPLETED_URANIUM_L => 'Depleted Uranium L',
        self::DATACORE_NUCLEAR_PHYSICS => 'Datacore - Nuclear Physics',
        self::DATACORE_MECHANICAL_ENGINEERING => 'Datacore - Mechanical Engineering',
    ];

    /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}>, typeNames: array<string, string>} */
    private array $sde;

    /** @var array<int, float> Jita price by typeId, an absent typeId has no price */
    private array $jitaPrices = [];

    /** @var list<list<int>> typeIds of each Jita price request */
    private array $pricedTypeIds = [];

    /** @var array<int, int> max production limit by blueprint typeId, an absent blueprint is missing from the SDE */
    private array $maxProductionLimits = [];

    private array $typeIdsWithoutSdeName = [];

    /** @var array{total: float, base: float, rig: float} structure bonus of the best structure of the owner */
    private array $ownerStructureBonus = ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0];

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private IndustryBlueprintRepository $blueprintRepository;
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
        $this->connection->method('fetchOne')->willReturnCallback(fn (string $sql, array $params): int => $this->hasCopyActivity($params[0]) ? 1 : 0);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );

        $this->blueprintRepository = $this->createMock(IndustryBlueprintRepository::class);
        $this->blueprintRepository->method('findBy')->willReturnCallback(fn (array $criteria): array => array_values(array_map(
            static fn (int $blueprintTypeId, int $maxProductionLimit): IndustryBlueprint => (new IndustryBlueprint())
                ->setTypeId($blueprintTypeId)
                ->setMaxProductionLimit($maxProductionLimit),
            array_keys(array_intersect_key($this->maxProductionLimits, array_flip($criteria['typeId']))),
            array_intersect_key($this->maxProductionLimits, array_flip($criteria['typeId'])),
        )));

        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturnCallback(function (array $typeIds): array {
            $this->pricedTypeIds[] = $typeIds;
            $prices = [];
            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->jitaPrices[$typeId] ?? null;
            }

            return $prices;
        });

        $this->service = $this->serviceInventing(self::HAIL_L_BASE_PROBABILITY);
        $this->owner = $this->createMock(User::class);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (R3, B1) : les 25 runs saisis sont lus comme
    // une quantité, ceil(25 / 40) = 1 run. Le golden nitrogen-fuel-block-25runs-me10-npc attend 25 runs et
    // Nitrogen Isotopes 10125 ; la BOM persistée demande 1 run et 405.
    public function testTwentyFiveRunsOfNitrogenFuelBlockCurrentlyBecomeABomOfOneRunIssue2(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 25, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [44, 'Enriched Uranium', 4],
                [3683, 'Oxygen', 20],
                [3689, 'Mechanical Parts', 4],
                [9832, 'Coolant', 9],
                [9848, 'Robotics', 1],
                [16272, 'Heavy Water', 153],
                [16273, 'Liquid Ozone', 315],
                [16275, 'Strontium Clathrates', 18],
                [17888, 'Nitrogen Isotopes', 405],
            ],
            array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getTypeId(), $bomItem->getTypeName(), $bomItem->getRequiredQuantity()],
                $this->materialBomItems($project),
            ),
        );
        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 1, null],
            ],
            $this->jobsIgnoringMeAndTe($project),
        );
        self::assertCount(11, $project->getBomItems());
    }

    #[Group('issue-23')]
    public function testFinalManufacturingJobCarriesTheMeAndTeOfTheProjectItem(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
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
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 0, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
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

        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17888 => 405],
            $this->materials($projectAtTeTwenty),
        );
        self::assertSame($this->materials($projectAtTeTwenty), $this->materials($projectAtTeZero));
    }

    #[Group('issue-23')]
    public function testFinalManufacturingJobOfAT2ProductCarriesTheMeAndTeOfTheProjectItem(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[self::HAIL_L, 'manufacturing', 'final', 1, 2, 4, 5_000, null]],
            array_values(array_filter($this->jobsOfType($project, self::HAIL_L), static fn (array $job): bool => $job[1] === 'manufacturing')),
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

    public function testStructureBonusOfTheOwnerReducesTheBomMaterials(): void
    {
        $this->ownerStructureBonus = ['total' => 2.98, 'base' => 1.0, 'rig' => 2.0];
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 8, 9848 => 1, 16272 => 149, 16273 => 306, 16275 => 18, 17888 => 393],
            $this->materials($project),
        );
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
            $this->sortedJobs($this->jobs($project)),
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
    public function testSameProductOnTwoItemsAtTheSameMeAndTeSharesOneFinalJob(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 2, 80, null],
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 2, null],
            ],
            $this->jobsIgnoringMeAndTe($project),
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2, #16 et #69 : 10 runs de Fernite Carbide
    // deviennent 1 run ; le Hydrogen Fuel Block, consommé par la réaction racine et ses deux réactions
    // intermédiaires (5 unités chacune), donne 3 runs (un par branche) au lieu de ceil(15 / 40) = 1, et ses
    // matériaux sont triplés. Les réactions sont en ME 0 / TE 0, les intermédiaires fabriqués en ME 10 / TE 20.
    public function testTenRunsOfFerniteCarbideCurrentlyBecomeOneRunAndCountTheFuelBlockOncePerBranchIssues2And16And69(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                44 => 12, 3683 => 60, 3689 => 12, 9832 => 27, 9848 => 3, 16272 => 459, 16273 => 945, 16275 => 54, 17889 => 1215,
                16639 => 100, 16642 => 100, 16635 => 100, 16636 => 100,
            ],
            $this->materials($project),
        );
        self::assertSame(
            [
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, 10_000, null],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 3, 10, 20, 120, self::FERNITE_CARBIDE],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 3, 0, 0, 3, self::FERNITE_CARBIDE],
            ],
            $this->jobs($project),
        );
    }

    #[Group('issue-2')]
    #[Group('issue-23')]
    #[Group('issue-105')]
    public function testTenRunsOfHailLCurrentlyBecomeOneRunInventedFromACopyOfTheT1BlueprintIssue2(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 10, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        $materials = $this->materials($project);
        ksort($materials);
        self::assertSame(
            [
                34 => 501, 35 => 400, 36 => 200, 37 => 74, 38 => 33, 44 => 24, 3683 => 120, 3689 => 24, 9832 => 54, 9848 => 6,
                11399 => 15, 16272 => 918, 16273 => 1890, 16274 => 405, 16275 => 108, 16633 => 100, 16635 => 100,
                16636 => 200, 16639 => 100, 16642 => 100, 16644 => 100, 16649 => 100, 17888 => 810, 17889 => 1215,
                self::DATACORE_NUCLEAR_PHYSICS => 1, self::DATACORE_MECHANICAL_ENGINEERING => 2,
            ],
            $materials,
        );
        self::assertSame(
            [
                [self::HAIL_L, 'manufacturing', 'final', 1, 2, 4, 5_000, null],
                [self::RAM_AMMUNITION_TECH, 'manufacturing', 'component', 1, 10, 20, 100, self::HAIL_L],
                [self::FERNITE_CARBIDE, 'reaction', 'component', 1, 0, 0, 10_000, self::HAIL_L],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component', 3, 10, 20, 120, self::FERNITE_CARBIDE],
                [self::FERNITE_ALLOY, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::CERAMIC_POWDER, 'reaction', 'component', 1, 0, 0, 200, self::FERNITE_CARBIDE],
                [self::FULLERIDES, 'reaction', 'component', 1, 0, 0, 3_000, self::HAIL_L],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'component', 2, 10, 20, 80, self::FULLERIDES],
                [self::CARBON_POLYMERS, 'reaction', 'component', 1, 0, 0, 200, self::FULLERIDES],
                [self::HELIUM_FUEL_BLOCK, 'manufacturing', 'component', 1, 10, 20, 40, self::CARBON_POLYMERS],
                [self::PLATINUM_TECHNITE, 'reaction', 'component', 1, 0, 0, 200, self::FULLERIDES],
                [self::RAM_AMMUNITION_TECH, 'copy', 'blueprint', 1, 0, 0, 1, self::HAIL_L],
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 3, 0, 0, 3, self::FERNITE_CARBIDE],
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2, self::FULLERIDES],
                [self::HELIUM_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::CARBON_POLYMERS],
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

        self::assertSame([], array_values(array_filter($this->jobsOfType($project, self::HAIL_L), static fn (array $job): bool => $job[1] === 'copy')));
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
        $this->jitaPrices = [self::DATACORE_NUCLEAR_PHYSICS => 68_500.0, self::DATACORE_MECHANICAL_ENGINEERING => 91_200.0, 11399 => 7_350.0];
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        $estimatedPrices = [];
        foreach ($this->materialBomItems($project) as $bomItem) {
            $estimatedPrices[$bomItem->getTypeId()] = $bomItem->getEstimatedPrice();
        }
        $estimatedPrices = array_intersect_key($estimatedPrices, array_flip([11399, self::DATACORE_NUCLEAR_PHYSICS, self::DATACORE_MECHANICAL_ENGINEERING]));
        ksort($estimatedPrices);
        self::assertSame(
            [11399 => 7_350.0, self::DATACORE_NUCLEAR_PHYSICS => 68_500.0, self::DATACORE_MECHANICAL_ENGINEERING => 91_200.0],
            $estimatedPrices,
        );
        self::assertCount(1, $this->pricedTypeIds);
        self::assertContains(self::DATACORE_NUCLEAR_PHYSICS, $this->pricedTypeIds[0]);
        self::assertContains(self::DATACORE_MECHANICAL_ENGINEERING, $this->pricedTypeIds[0]);
    }

    #[Group('issue-2')]
    #[Group('issue-105')]
    public function testTenRunsOfHailLCurrentlyInventForTheOneManufacturingRunOfTheBomIssue2(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 10, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[1], [1], [1]],
            [
                array_map(static fn (GroupIndustryBomItem $bomItem): ?int => $bomItem->getRuns(), $this->jobsOf($project, self::HAIL_L, 'manufacturing')),
                array_map(static fn (GroupIndustryBomItem $bomItem): ?int => $bomItem->getRuns(), $this->jobsOf($project, self::HAIL_L, 'invention')),
                array_map(static fn (GroupIndustryBomItem $bomItem): ?int => $bomItem->getRuns(), $this->jobsOf($project, self::DEPLETED_URANIUM_L, 'copy')),
            ],
        );
    }

    #[Group('issue-2')]
    #[Group('issue-105')]
    public function testSevenManufacturingRunsOfHailLNeedThreeInventionAttemptsAndThreeCopiesOfTheT1Blueprint(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 35_000, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame([7], array_map(static fn (GroupIndustryBomItem $bomItem): ?int => $bomItem->getRuns(), $this->jobsOf($project, self::HAIL_L, 'manufacturing')));
        self::assertSame($this->hailLInventionJobs(3), $this->inventionJobs($project));
        self::assertSame(
            [[self::DATACORE_NUCLEAR_PHYSICS, 'Datacore - Nuclear Physics', 3], [self::DATACORE_MECHANICAL_ENGINEERING, 'Datacore - Mechanical Engineering', 6]],
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
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, null],
            ],
            $this->jobs($project),
        );
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17888 => 405],
            $this->materials($project),
        );
    }

    #[Group('issue-105')]
    public function testIntermediatesOfAnInventedProductKeepTheCopyOfTheirBlueprint(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'copy', 'blueprint', 2, 0, 0, 2, self::FULLERIDES],
                [self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint', 3, 0, 0, 3, self::FERNITE_CARBIDE],
                [self::HELIUM_FUEL_BLOCK, 'copy', 'blueprint', 1, 0, 0, 1, self::CARBON_POLYMERS],
                [self::RAM_AMMUNITION_TECH, 'copy', 'blueprint', 1, 0, 0, 1, self::HAIL_L],
            ],
            $this->sortedJobs(array_values(array_filter(
                $this->jobs($project),
                static fn (array $job): bool => $job[1] === 'copy' && !in_array($job[0], [self::HAIL_L, self::DEPLETED_URANIUM_L], true),
            ))),
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

        self::assertInstanceOf(\RuntimeException::class, $missingProbability, 'Expected a RuntimeException for the missing invention probability');
        self::assertMatchesRegularExpression('/\b'.self::HAIL_L.'\b/', $missingProbability->getMessage());
        self::assertMatchesRegularExpression('/\b'.self::DEPLETED_URANIUM_L_BLUEPRINT.'\b/', $missingProbability->getMessage());
        self::assertCount(0, $project->getBomItems());
    }

    #[Group('issue-105')]
    public function testInventionWithoutProbabilityPersistsNoProject(): void
    {
        $service = $this->serviceInventing(null);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $missingProbability = null;
        try {
            $service->createProject($this->owner, new CreateProjectData(
                name: 'Hail run',
                items: [['typeId' => self::HAIL_L, 'typeName' => 'Hail L', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 1]],
            ));
        } catch (\RuntimeException $exception) {
            $missingProbability = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $missingProbability, 'Expected a RuntimeException for the missing invention probability');
        self::assertSame([], $persisted);
    }

    #[Group('issue-105')]
    public function testTwoHailLItemsShareOneInventionAndOneT1CopySizedOnTheSumOfTheirManufacturingRuns(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::HAIL_L, 35_000, 0, 0]]);

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

    #[Group('issue-106')]
    #[Group('issue-69')]
    public function testProjectItemAlsoNeededAsAnIntermediateKeepsItsFinalJobNextToTheIntermediateJobAndCurrentlyFlattensBothTreesIssue69(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::FERNITE_CARBIDE, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::FERNITE_CARBIDE, 'reaction', 'component', 1, 0, 0, 10_000, self::HAIL_L],
                [self::FERNITE_CARBIDE, 'reaction', 'final', 1, 0, 0, 10_000, null],
            ],
            $this->sortedJobs($this->jobsOfType($project, self::FERNITE_CARBIDE)),
        );
        self::assertSame([self::HAIL_L, self::FERNITE_CARBIDE], $this->finalJobTypeIds($project));
        self::assertSame([6, 6, 2], [
            $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing')[0]->getRuns(),
            $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK, 'copy')[0]->getRuns(),
            $this->jobsOf($project, self::FERNITE_ALLOY, 'reaction')[0]->getRuns(),
        ]);
        self::assertSame(2430, $this->materials($project)[17889]);
    }

    #[Group('issue-106')]
    public function testOrderOfTheProjectItemsLeavesTheJobsAndMaterialsOfTheBomUnchanged(): void
    {
        $intermediateFirstProject = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::FERNITE_CARBIDE, 1, 0, 0]]);
        $projectItemFirstProject = $this->projectOf([[self::FERNITE_CARBIDE, 1, 0, 0], [self::HAIL_L, 1, 2, 4]]);

        $this->service->buildBom($intermediateFirstProject, $this->owner);
        $this->service->buildBom($projectItemFirstProject, $this->owner);

        self::assertSame(
            $this->sortedJobs($this->jobs($intermediateFirstProject)),
            $this->sortedJobs($this->jobs($projectItemFirstProject)),
        );
        $intermediateFirstMaterials = $this->materials($intermediateFirstProject);
        $projectItemFirstMaterials = $this->materials($projectItemFirstProject);
        ksort($intermediateFirstMaterials);
        ksort($projectItemFirstMaterials);
        self::assertSame($intermediateFirstMaterials, $projectItemFirstMaterials);
    }

    #[Group('issue-106')]
    #[Group('issue-23')]
    public function testManufacturedProjectItemAlsoNeededAsAnIntermediateKeepsItsFinalJobNextToTheIntermediateJob(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'component', 2, 10, 20, 80, self::FULLERIDES],
                [self::NITROGEN_FUEL_BLOCK, 'manufacturing', 'final', 1, 10, 20, 40, null],
            ],
            $this->sortedJobs(array_values(array_filter(
                $this->jobsOfType($project, self::NITROGEN_FUEL_BLOCK),
                static fn (array $job): bool => $job[1] === 'manufacturing',
            ))),
        );
    }

    #[Group('issue-106')]
    #[Group('issue-105')]
    public function testProjectItemAlsoNeededAsAnIntermediateKeepsTheMaterialsOfBothTrees(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4], [self::FERNITE_CARBIDE, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        $materials = $this->materials($project);
        ksort($materials);
        self::assertSame(
            [
                34 => 501, 35 => 400, 36 => 200, 37 => 74, 38 => 33, 44 => 36, 3683 => 180, 3689 => 36, 9832 => 81, 9848 => 9,
                11399 => 15, 16272 => 1377, 16273 => 2835, 16274 => 405, 16275 => 162, 16633 => 100, 16635 => 200,
                16636 => 300, 16639 => 200, 16642 => 200, 16644 => 100, 16649 => 100, 17888 => 810, 17889 => 2430,
                self::DATACORE_NUCLEAR_PHYSICS => 1, self::DATACORE_MECHANICAL_ENGINEERING => 2,
            ],
            $materials,
        );
    }

    public function testBlacklistedIntermediateBecomesAMaterialOfTheBom(): void
    {
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);
        $project->setBlacklistTypeIds([self::FERNITE_CARBIDE]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(2940, $this->materials($project)[self::FERNITE_CARBIDE]);
        self::assertSame([], $this->jobsOf($project, self::FERNITE_CARBIDE, 'reaction'));
        self::assertSame([], $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing'));
    }

    public function testBlacklistedGroupIsResolvedToItsPublishedTypesFromTheSde(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([['type_id' => (string) self::FULLERIDES]]);
        $project = $this->projectOf([[self::HAIL_L, 1, 2, 4]]);
        $project->setBlacklistGroupIds([self::COMPOSITE_GROUP_ID]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(1176, $this->materials($project)[self::FULLERIDES]);
        self::assertSame([], $this->jobsOf($project, self::FULLERIDES, 'reaction'));
        self::assertSame([], $this->jobsOf($project, self::NITROGEN_FUEL_BLOCK, 'manufacturing'));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10) : un matériau sans prix Jita garde un
    // prix estimé null, les lignes de job n'ont jamais de prix.
    public function testMaterialsCurrentlyTakeTheirEstimatedPriceFromJitaAndKeepNullWithoutAPriceIssue8(): void
    {
        $this->jitaPrices = [17888 => 512.5, 9848 => 76_000.0];
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $estimatedPrices = [];
        foreach ($project->getBomItems() as $bomItem) {
            $estimatedPrices[$bomItem->getTypeId().($bomItem->isJob() ? ':'.$bomItem->getActivityType() : '')] = $bomItem->getEstimatedPrice();
        }
        self::assertSame(
            [
                '44' => null, '3683' => null, '3689' => null, '9832' => null, '9848' => 76_000.0, '16272' => null,
                '16273' => null, '16275' => null, '17888' => 512.5, '4051:manufacturing' => null, '4051:copy' => null,
            ],
            $estimatedPrices,
        );
        self::assertSame([[44, 3683, 3689, 9832, 9848, 16272, 16273, 16275, 17888]], $this->pricedTypeIds);
    }

    public function testJobAboveTheMaxProductionLimitOfItsBlueprintIsSplitButNotItsCopy(): void
    {
        $this->maxProductionLimits = [self::CAPITAL_PROPULSION_ENGINE + self::BLUEPRINT_TYPE_ID_OFFSET => 15];
        $project = $this->projectOf([[self::FENRIR, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::CAPITAL_PROPULSION_ENGINE, 'manufacturing', 'component', 15, 10, 20, 15, self::FENRIR],
                [self::CAPITAL_PROPULSION_ENGINE, 'manufacturing', 'component', 5, 10, 20, 5, self::FENRIR],
                [self::CAPITAL_PROPULSION_ENGINE, 'copy', 'blueprint', 20, 0, 0, 20, self::FENRIR],
            ],
            $this->jobsOfType($project, self::CAPITAL_PROPULSION_ENGINE),
        );
    }

    public function testJobAtTheMaxProductionLimitOfItsBlueprintIsNotSplit(): void
    {
        $this->maxProductionLimits = [self::CAPITAL_PROPULSION_ENGINE + self::BLUEPRINT_TYPE_ID_OFFSET => 20];
        $project = $this->projectOf([[self::FENRIR, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::CAPITAL_PROPULSION_ENGINE, 'manufacturing', 'component', 20, 10, 20, 20, self::FENRIR],
                [self::CAPITAL_PROPULSION_ENGINE, 'copy', 'blueprint', 20, 0, 0, 20, self::FENRIR],
            ],
            $this->jobsOfType($project, self::CAPITAL_PROPULSION_ENGINE),
        );
    }

    public function testManufacturingJobMissingFromTheSdeLimitsIsSplitAt10000Runs(): void
    {
        $project = $this->projectOf([[self::WIDOW, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::TITANIUM_DIBORITE_ARMOR_PLATE, 'manufacturing', 'component', 10_000, 10, 20, 10_000, self::WIDOW],
                [self::TITANIUM_DIBORITE_ARMOR_PLATE, 'manufacturing', 'component', 8_750, 10, 20, 8_750, self::WIDOW],
                [self::TITANIUM_DIBORITE_ARMOR_PLATE, 'copy', 'blueprint', 18_750, 0, 0, 18_750, self::WIDOW],
            ],
            $this->jobsOfType($project, self::TITANIUM_DIBORITE_ARMOR_PLATE),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 : aucune recette golden n'atteint 501 runs de
    // réaction à partir d'un run ; les 501 runs de Fernite Alloy viennent ici des runs saisis lus comme une quantité,
    // ceil(100001 / 200) = 501. Le découpage à 500 runs par défaut, lui, n'est pas suspecté.
    public function testReactionMissingFromTheSdeLimitsIsSplitAt500RunsIssue2(): void
    {
        $project = $this->projectOf([[self::FERNITE_ALLOY, 100_001, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [
                [self::FERNITE_ALLOY, 'reaction', 'final', 500, 0, 0, 100_000, null],
                [self::FERNITE_ALLOY, 'reaction', 'final', 1, 0, 0, 200, null],
            ],
            $this->jobsOfType($project, self::FERNITE_ALLOY),
        );
    }

    public function testProductWithoutBlueprintNorReactionThrowsAndLeavesTheBomEmpty(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 10, 20], [self::MORPHITE, 1, 0, 0]]);

        try {
            $this->service->buildBom($project, $this->owner);
            self::fail('Expected a RuntimeException');
        } catch (\RuntimeException $exception) {
            self::assertSame('No blueprint or reaction found for product type ID '.self::MORPHITE, $exception->getMessage());
        }
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
            [$materials[self::NITROGEN_ISOTOPES], $materials[self::FERNITE_CARBIDE], $materials[self::FULLERIDES]],
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

    // CARACTÉRISATION : comportement actuel, suspecté faux (aucune issue connue) : buildBom() n'efface rien. Rappelé
    // sur un projet déjà doté d'une BOM, il ajoute une seconde BOM complète à côté de la première ; les lignes
    // existantes, leur quantité fournie et les contributions approuvées ou en attente qui les visent ne bougent pas.
    public function testRebuildingTheBomCurrentlyAppendsASecondFullBomNextToTheContributedLines(): void
    {
        $project = $this->projectOf([[self::NITROGEN_FUEL_BLOCK, 1, 0, 0]]);
        $this->service->buildBom($project, $this->owner);
        $isotopes = $this->bomItemsOf($project, self::NITROGEN_ISOTOPES)[0];
        $finalJob = $this->jobBomItems($project)[0];
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

        $finalJob = $this->jobsOf($project, self::NITROGEN_FUEL_BLOCK, 'manufacturing')[0];
        self::assertSame([1, 40], [$finalJob->getRuns(), $finalJob->getRequiredQuantity()]);
    }

    #[Group('issue-107')]
    public function testIntermediateManufacturingJobRequiresItsRunsTimesTheUnitsPerRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $intermediateJob = $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing')[0];
        self::assertSame(['component', 3, 120], [$intermediateJob->getJobGroup(), $intermediateJob->getRuns(), $intermediateJob->getRequiredQuantity()]);
    }

    #[Group('issue-107')]
    public function testFinalReactionJobRequiresItsRunsTimesTheUnitsPerRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $finalJob = $this->jobsOf($project, self::FERNITE_CARBIDE, 'reaction')[0];
        self::assertSame(['final', 1, 10_000], [$finalJob->getJobGroup(), $finalJob->getRuns(), $finalJob->getRequiredQuantity()]);
    }

    #[Group('issue-107')]
    public function testEachLineOfASplitReactionJobRequiresItsOwnRunsTimesTheUnitsPerRun(): void
    {
        $project = $this->projectOf([[self::FERNITE_ALLOY, 100_001, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[500, 100_000], [1, 200]],
            array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getRuns(), $bomItem->getRequiredQuantity()],
                $this->jobsOf($project, self::FERNITE_ALLOY, 'reaction'),
            ),
        );
    }

    #[Group('issue-107')]
    public function testEachLineOfASplitJobOfOneUnitPerRunRequiresItsOwnRuns(): void
    {
        $this->maxProductionLimits = [self::CAPITAL_PROPULSION_ENGINE + self::BLUEPRINT_TYPE_ID_OFFSET => 15];
        $project = $this->projectOf([[self::FENRIR, 1, 0, 0]]);

        $this->service->buildBom($project, $this->owner);

        self::assertSame(
            [[15, 15], [5, 5]],
            array_map(
                static fn (GroupIndustryBomItem $bomItem): array => [$bomItem->getRuns(), $bomItem->getRequiredQuantity()],
                $this->jobsOf($project, self::CAPITAL_PROPULSION_ENGINE, 'manufacturing'),
            ),
        );
    }

    #[Group('issue-107')]
    public function testCopyJobRequiresItsRunsAndNotUnits(): void
    {
        $project = $this->projectOf([[self::FERNITE_CARBIDE, 10, 10, 20]]);

        $this->service->buildBom($project, $this->owner);

        $copyJob = $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK, 'copy')[0];
        self::assertSame(['blueprint', 3, 3], [$copyJob->getJobGroup(), $copyJob->getRuns(), $copyJob->getRequiredQuantity()]);
    }

    /** @param list<array{int, int, int, int}> $items typeId, runs, ME, TE */
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

    /** @return array<int, int> required quantity by material typeId, in BOM order */
    private function materials(GroupIndustryProject $project): array
    {
        $materials = [];
        foreach ($this->materialBomItems($project) as $bomItem) {
            $materials[$bomItem->getTypeId()] = $bomItem->getRequiredQuantity();
        }

        return $materials;
    }

    /** @return list<array{int, string, string, int|null, int|null, int|null, int, int|null}> typeId, activity, job group, runs, ME, TE, required quantity, parent typeId */
    private function jobs(GroupIndustryProject $project): array
    {
        return array_map(static fn (GroupIndustryBomItem $bomItem): array => [
            $bomItem->getTypeId(),
            (string) $bomItem->getActivityType(),
            (string) $bomItem->getJobGroup(),
            $bomItem->getRuns(),
            $bomItem->getMeLevel(),
            $bomItem->getTeLevel(),
            $bomItem->getRequiredQuantity(),
            $bomItem->getParentTypeId(),
        ], $this->jobBomItems($project));
    }

    /** @return list<array{int, string, string, int|null, int, int|null}> typeId, activity, job group, runs, required quantity, parent typeId */
    private function jobsIgnoringMeAndTe(GroupIndustryProject $project): array
    {
        return array_map(
            static fn (array $job): array => [$job[0], $job[1], $job[2], $job[3], $job[6], $job[7]],
            $this->jobs($project),
        );
    }

    /** @return list<array{int, string, string, int|null, int|null, int|null, int, int|null}> same columns as jobs() */
    private function jobsOfType(GroupIndustryProject $project, int $typeId): array
    {
        return array_values(array_filter($this->jobs($project), static fn (array $job): bool => $job[0] === $typeId));
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

    /** @return list<GroupIndustryBomItem> */
    private function materialBomItems(GroupIndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (GroupIndustryBomItem $bomItem): bool => !$bomItem->isJob()));
    }

    /** @return list<GroupIndustryBomItem> */
    private function jobBomItems(GroupIndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (GroupIndustryBomItem $bomItem): bool => $bomItem->isJob()));
    }

    /** @return list<GroupIndustryBomItem> */
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
        return $this->sortedJobs(array_values(array_filter(
            $this->jobs($project),
            static fn (array $job): bool => $job[1] === 'invention' || ($job[1] === 'copy' && in_array($job[0], [self::HAIL_L, self::DEPLETED_URANIUM_L], true)),
        )));
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

    /** @return list<GroupIndustryBomItem> */
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

    private function hasCopyActivity(int $blueprintTypeId): bool
    {
        $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;

        return $recipe !== null && $recipe['activity'] === 'manufacturing';
    }

    private function treeServiceOnGoldenSde(EntityManagerInterface $entityManager): IndustryTreeService
    {
        $recipes = $this->sde['recipes'];

        $productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId) use ($recipes): ?IndustryActivityProduct {
                $recipe = $recipes[(string) $productTypeId] ?? null;
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

        $materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($recipes): array {
                $productTypeId = $criteria['typeId'] - self::BLUEPRINT_TYPE_ID_OFFSET;
                $materials = [];
                foreach ($recipes[(string) $productTypeId]['materials'] as $materialTypeId => $baseQuantityPerRun) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($criteria['typeId'])
                        ->setActivityId($criteria['activityId'])
                        ->setMaterialTypeId((int) $materialTypeId)
                        ->setQuantity($baseQuantityPerRun);
                }

                return $materials;
            },
        );

        $typeRepository = $this->typeRepositoryOnGoldenSde();

        $noStructure = ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null];
        $bonusService = $this->createMock(IndustryBonusService::class);
        $bonusService->method('findBestStructureForProduct')->willReturnCallback(
            fn (User $user): array => $user === $this->owner ? [...$noStructure, 'bonus' => $this->ownerStructureBonus] : $noStructure,
        );
        $bonusService->method('getCategoryForProduct')->willReturn(null);

        return new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $bonusService);
    }

    private function serviceInventing(?float $hailLBaseProbability): GroupIndustryProjectService
    {
        return new GroupIndustryProjectService(
            $this->treeServiceOnGoldenSde($this->entityManager),
            $this->jitaMarketService,
            $this->entityManager,
            $this->blueprintRepository,
            new InMemoryInventionCatalog([
                self::HAIL_L => new InventionRecipe(
                    t1BlueprintTypeId: self::DEPLETED_URANIUM_L_BLUEPRINT,
                    t1ProductTypeId: self::DEPLETED_URANIUM_L,
                    baseProbability: $hailLBaseProbability,
                    baseRuns: new Runs(self::HAIL_L_INVENTED_BPC_RUNS),
                    datacores: [
                        new RecipeMaterial(self::DATACORE_NUCLEAR_PHYSICS, new Quantity(self::NUCLEAR_PHYSICS_PER_ATTEMPT)),
                        new RecipeMaterial(self::DATACORE_MECHANICAL_ENGINEERING, new Quantity(self::MECHANICAL_ENGINEERING_PER_ATTEMPT)),
                    ],
                ),
            ]),
        );
    }

    private function typeRepositoryOnGoldenSde(): InvTypeRepository
    {
        $typeOf = fn (int $typeId): ?InvType => !in_array($typeId, $this->typeIdsWithoutSdeName, true) && isset($this->sde['typeNames'][(string) $typeId])
            ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
            : null;

        $typeRepository = $this->createMock(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback($typeOf);
        $typeRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($typeOf, (array) $criteria['typeId']))),
        );

        return $typeRepository;
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
