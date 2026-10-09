<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\ProjectProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Characterization of the production tree displayed by GET /industry/projects/{id} (ProjectProvider::provide()->tree),
 * before buildProductionTree() is replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 4).
 *
 * The real IndustryTreeService and IndustryBlacklistService run on the SDE extract of the goldens
 * (tests/Fixtures/Golden/sde-blueprint-recipes.json). Stubbed: the SDE repositories, the project repository,
 * the database connection, the security token, the resource mapper (steps and costs are out of scope here) and the
 * structure lookup of IndustryBonusService (the goldens fix the formula, not the structure choice).
 *
 * The whole tree is pinned in a JSON snapshot per golden, in tests/Fixtures/Characterization/ProjectProvider/.
 * A missing snapshot is written from the current output and the test is marked incomplete. Blueprint type IDs in the
 * snapshots are synthetic (product type ID + 1000000) and hasCopy is always false (no copying activity stubbed).
 */
#[CoversClass(ProjectProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class ProjectProviderGoldenTreeCharacterizationTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__.'/../../../../Fixtures/Golden/';
    private const string SNAPSHOT_DIR = __DIR__.'/../../../../Fixtures/Characterization/ProjectProvider/';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int NOMAD = 28846;
    private const int ROBOTICS = 9848;

    private const string FUEL_BLOCK_CATEGORY = 'ammunition';
    private const string REACTION_CATEGORY = 'composite_reaction';
    // Raitaru without rig: 1 % structure material bonus (IndustryBonusService::STRUCTURE_MATERIAL_BONUSES)
    private const float RAITARU_BASE_MATERIAL_BONUS = 1.0;
    // Tatara in nullsec with Standup L-Set Reactor Efficiency II: 2.4 % × 1.1
    private const float TATARA_REACTOR_RIG_BONUS = 2.64;

    /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}>, typeNames: array<string, string>} */
    private array $sde;

    private bool $pilotOwnsTataraAndRaitaru = false;

    /** @var list<int> */
    private array $blacklistedTypeIds = [];

    private User $pilot;
    private IndustryProject $project;
    private Security $security;
    private ProjectProvider $provider;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);

        $this->pilot = $this->createStub(User::class);
        $this->pilot->method('getIndustryBlacklistTypeIds')->willReturnCallback(fn (): array => $this->blacklistedTypeIds);
        $this->pilot->method('getIndustryBlacklistGroupIds')->willReturn([]);

        $this->project = (new IndustryProject())->setUser($this->pilot);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());

        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturn($this->pilot);

        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProject => $id->equals($this->project->getId()) ? $this->project : null,
        );

        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturnCallback(static fn (): ProjectResource => new ProjectResource());

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $entityManager->method('getConnection')->willReturn($connection);

        $this->provider = new ProjectProvider(
            $this->security,
            $projectRepository,
            $mapper,
            $this->treeServiceOnGoldenSde($entityManager),
            new IndustryBlacklistService($entityManager),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1) : les 25 runs du projet sont lus comme
    // une quantité. L'arbre affiche 1 run (40 unités) au lieu de 25 runs (1000 unités) : Nitrogen Isotopes 405 au lieu
    // de 10125, Robotics 1 au lieu de 25 (golden nitrogen-fuel-block-25runs-me10-npc).
    public function testTreeOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyShowsOneRunIssue2(): void
    {
        $tree = $this->treeOfProject(self::NITROGEN_FUEL_BLOCK, runs: 25, meLevel: 10);

        self::assertSame(
            [25, 1, 40, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17888 => 405],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $this->assertTreeMatchesSnapshot('nitrogen-fuel-block-25runs-me10-npc', $tree);
    }

    public function testTreeOfOneRunOfNitrogenFuelBlockAtMeZeroKeepsTheBaseQuantitiesOfTheSde(): void
    {
        $tree = $this->treeOfProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 0);

        self::assertSame(
            [44 => 4, 3683 => 22, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 170, 16273 => 350, 16275 => 20, 17888 => 450],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([false], array_values(array_unique(array_column($tree['materials'], 'isBuildable'))));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2 et #16. Fernite Carbide, 10 runs, Tatara
    // nullsec (L-Set Reactor Efficiency II) et Raitaru sans rig :
    // - #2 : 10 runs à 10000 unités par run deviennent 1 run ; Ceramic Powder et Fernite Alloy 98 au lieu de 974 ;
    // - #16 : le Hydrogen Fuel Block, consommé à deux profondeurs, est un sous-arbre par branche (3 jobs d'un run).
    public function testTreeOfTenRunsOfFerniteCarbideInATataraCurrentlyShowsOneRunAndOneFuelBlockJobPerBranchIssues2And16(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 10, meLevel: 10);

        self::assertSame(
            [10, 1, 10000, 'reaction', self::TATARA_REACTOR_RIG_BONUS, 'Tatara', self::REACTION_CATEGORY],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName'], $tree['productCategory']],
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 98, self::CERAMIC_POWDER => 98],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $fuelBlockJobs = $this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK);
        self::assertSame(
            [[5, 1, 1, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS], [5, 1, 2, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS], [5, 1, 2, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS]],
            array_map(static fn (array $job): array => [$job['quantity'], $job['runs'], $job['depth'], $job['structureName'], $job['structureBonus']], $fuelBlockJobs),
        );
        $this->assertTreeMatchesSnapshot('fernite-carbide-10runs-tatara-nullsec', $tree);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #6 (R4) : dans le job de réaction racine, le
    // Hydrogen Fuel Block reçoit le bonus de la meilleure structure de SA catégorie (Raitaru, ×0,99) au lieu du rig du
    // job consommateur (Tatara, ×0,9736). Pour 10 vrais runs (100000 unités saisies, à cause de #2) : 50 × 0,99 = 49,5
    // → 50 au lieu de 49 (golden fernite-carbide-10runs-tatara-nullsec). Les deux autres matériaux suivent le golden.
    public function testFuelBlockConsumedByAReactionCurrentlyGetsTheBonusOfItsOwnCategoryIssue6(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 100_000, meLevel: 0);

        self::assertSame(10, $tree['runs']);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 50, self::FERNITE_ALLOY => 974, self::CERAMIC_POWDER => 974],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #15 : chaque intermédiaire fabriqué reçoit un
    // ME 10 en dur, quel que soit le ME du projet. À ME 0, le Hydrogen Fuel Block consommé par la réaction calcule
    // ses matériaux à ME 10 (Robotics max(1 run, 0,9) = 1, Hydrogen Isotopes 450 × 0,9 = 405).
    public function testTreeOfFerniteCarbideAtMeZeroCurrentlyBuildsTheFuelBlockIntermediateAtHardCodedMeTenIssue15(): void
    {
        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        $fuelBlockJob = $this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK)[0];
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17889 => 405],
            array_column($fuelBlockJob['materials'], 'quantity', 'typeId'),
        );
    }

    public function testTreeIgnoresTheMeOfTheProjectOnAReactionRoot(): void
    {
        $treeAtMeTen = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10);
        $treeAtMeZero = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            array_column($treeAtMeTen['materials'], 'quantity', 'typeId'),
        );
        self::assertSame($treeAtMeZero, $treeAtMeTen);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #69 et #16 : la demande d'un intermédiaire
    // n'est pas agrégée entre branches avant l'arrondi en runs. Chaque consommateur a son propre sous-arbre : 50 jobs
    // de Hydrogen Fuel Block et 21 de Nitrogen Fuel Block. Le golden Ravworks (fait foi) a un job par intermédiaire.
    public function testTreeOfOneNomadCurrentlyRepeatsEachIntermediatePerConsumerIssues69And16(): void
    {
        $tree = $this->treeOfProject(self::NOMAD, runs: 1, meLevel: 10);

        self::assertSame(
            [1, 1, 1, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 50, self::NITROGEN_FUEL_BLOCK => 21],
            [self::HYDROGEN_FUEL_BLOCK => count($this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK)), self::NITROGEN_FUEL_BLOCK => count($this->blueprintNodesOf($tree, self::NITROGEN_FUEL_BLOCK))],
        );
        $this->assertTreeMatchesSnapshot('nomad-1run-me10-no-structure', $tree);
    }

    public function testBlacklistedIntermediateIsShownAsANonBuildableMaterialWithoutSubtree(): void
    {
        $this->blacklistedTypeIds = [self::HYDROGEN_FUEL_BLOCK];

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        self::assertSame([], $this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(
            ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'isBuildable' => false, 'activityType' => null],
            $tree['materials'][0],
        );
    }

    public function testTreeIsNullWhenTheProductHasNoBlueprintNorReaction(): void
    {
        $resource = $this->provideProject(self::ROBOTICS, runs: 1, meLevel: 10);

        self::assertNull($resource->tree);
    }

    public function testProjectOfAnotherPilotIsNotFound(): void
    {
        $this->project->setUser($this->createStub(User::class));

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Get(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $provider = new ProjectProvider(
            $security,
            $this->createStub(IndustryProjectRepository::class),
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(IndustryTreeService::class),
            $this->createStub(IndustryBlacklistService::class),
        );

        $this->expectException(UnauthorizedHttpException::class);

        $provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function treeOfProject(int $productTypeId, int $runs, int $meLevel): array
    {
        $tree = $this->provideProject($productTypeId, $runs, $meLevel)->tree;
        self::assertIsArray($tree);

        return $tree;
    }

    private function provideProject(int $productTypeId, int $runs, int $meLevel): ProjectResource
    {
        $this->project->setProductTypeId($productTypeId)->setRuns($runs)->setMeLevel($meLevel);

        return $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function assertTreeMatchesSnapshot(string $name, array $tree): void
    {
        $snapshotFile = self::SNAPSHOT_DIR.$name.'.json';
        if (!is_file($snapshotFile)) {
            @mkdir(self::SNAPSHOT_DIR, 0o775, true);
            file_put_contents($snapshotFile, json_encode($tree, \JSON_PRETTY_PRINT | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
            self::markTestIncomplete("Snapshot written: {$snapshotFile}");
        }

        self::assertSame(json_decode((string) file_get_contents($snapshotFile), true, flags: \JSON_THROW_ON_ERROR), $tree);
    }

    /**
     * Every job node (the `blueprint` of a material) producing the given type, depth-first.
     *
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private function blueprintNodesOf(array $node, int $productTypeId): array
    {
        $found = [];
        foreach ($node['materials'] as $material) {
            if (!isset($material['blueprint'])) {
                continue;
            }
            if ($material['blueprint']['productTypeId'] === $productTypeId) {
                $found[] = $material['blueprint'];
            }
            $found = [...$found, ...$this->blueprintNodesOf($material['blueprint'], $productTypeId)];
        }

        return $found;
    }

    private function treeServiceOnGoldenSde(EntityManagerInterface $entityManager): IndustryTreeService
    {
        $recipes = $this->sde['recipes'];

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
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

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
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

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback(
            fn (int $typeId): ?InvType => isset($this->sde['typeNames'][(string) $typeId])
                ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
                : null,
        );

        return new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $this->bonusServiceOfThePilot());
    }

    /**
     * Without structure, every lookup returns no bonus. With structures, the pilot owns a Tatara in nullsec with
     * Standup L-Set Reactor Efficiency II (reactions) and a Raitaru without rig (fuel blocks), as in the Fernite golden.
     */
    private function bonusServiceOfThePilot(): IndustryBonusService
    {
        $tatara = (new IndustryStructureConfig())->setName('Tatara')->setStructureType('tatara')->setSecurityType('nullsec')
            ->setRigs(['Standup L-Set Reactor Efficiency II']);
        $raitaru = (new IndustryStructureConfig())->setName('Raitaru')->setStructureType('raitaru')->setSecurityType('nullsec');
        $tataraForReactions = ['structure' => $tatara, 'bonus' => ['total' => self::TATARA_REACTOR_RIG_BONUS, 'base' => 0.0, 'rig' => self::TATARA_REACTOR_RIG_BONUS], 'category' => self::REACTION_CATEGORY];
        $raitaruForFuelBlocks = ['structure' => $raitaru, 'bonus' => ['total' => self::RAITARU_BASE_MATERIAL_BONUS, 'base' => self::RAITARU_BASE_MATERIAL_BONUS, 'rig' => 0.0], 'category' => self::FUEL_BLOCK_CATEGORY];
        $noStructure = ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null];
        $fuelBlocks = [self::HYDROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK];

        $bonusService = $this->createStub(IndustryBonusService::class);
        $bonusService->method('getCategoryForProduct')->willReturnCallback(
            fn (int $typeId, bool $isReaction = false): ?string => match (true) {
                !$this->pilotOwnsTataraAndRaitaru => null,
                $isReaction => self::REACTION_CATEGORY,
                in_array($typeId, $fuelBlocks, true) => self::FUEL_BLOCK_CATEGORY,
                default => null,
            },
        );
        $bonusService->method('findBestStructureForProduct')->willReturnCallback(
            fn (User $user, int $typeId, bool $isReaction = false): array => match (true) {
                !$this->pilotOwnsTataraAndRaitaru => $noStructure,
                $isReaction => $tataraForReactions,
                default => $raitaruForFuelBlocks,
            },
        );
        $bonusService->method('findBestStructureForCategory')->willReturnCallback(
            fn (User $user, string $category, bool $isReaction = false): array => match ($category) {
                self::REACTION_CATEGORY => $tataraForReactions,
                self::FUEL_BLOCK_CATEGORY => $raitaruForFuelBlocks,
                default => $noStructure,
            },
        );

        return $bonusService;
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
