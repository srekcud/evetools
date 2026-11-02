<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\Industry\InventionService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\BuildsProjectOnGoldenSde;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
#[Group('issue-202')]
final class IndustryResourceMapperDeletableStepTest extends TestCase
{
    use BuildsProjectOnGoldenSde;
    use ResolvesTypeNamesFromSde;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const string FERNITE_CARBIDE_SPLIT_GROUP = 'fernite-carbide';
    private const string FERNITE_ALLOY_SPLIT_GROUP = 'fernite-alloy';
    private const string TITANIUM_CARBIDE_SPLIT_GROUP = 'titanium-carbide';

    public function testOnlyTheStepsThatTheApiAcceptsToDeleteAreDeletable(): void
    {
        $project = $this->ferniteCarbideProject(runs: 10);
        $ferniteCarbide = $this->stepOf($project, self::FERNITE_CARBIDE, 'reaction', runs: 10, quantity: 100_000, depth: 0);
        $firstAlloyJob = $this->alloyJobOf($project, splitIndex: 0, runs: 3)
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_202_301)->setRuns(3)->setStatus('active')->setFacilityId(1_035_466_617_946)->setFacilityName('Tatara of a corporation'));
        $secondAlloyJob = $this->alloyJobOf($project, splitIndex: 1, runs: 2);
        $hydrogenFuelBlock = $this->stepOf($project, self::HYDROGEN_FUEL_BLOCK_TYPE_ID, 'manufacturing', runs: 2, quantity: 75, depth: 2);

        self::assertSame(
            [false, true, false, true],
            $this->deletableOf([$firstAlloyJob, $secondAlloyJob, $ferniteCarbide, $hydrogenFuelBlock]),
        );
    }

    public function testSingleStepHoldingStockIsNotDeletableUnlikeTheSameStepWithoutStock(): void
    {
        $projectWithStock = $this->ferniteCarbideProject(runs: 4);
        $this->stepOf($projectWithStock, self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0);
        $ferniteAlloyWithStock = $this->stepOf($projectWithStock, self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1)->setInStockQuantity(100);
        $projectWithoutStock = $this->ferniteCarbideProject(runs: 4);
        $this->stepOf($projectWithoutStock, self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0);
        $ferniteAlloyWithoutStock = $this->stepOf($projectWithoutStock, self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1);

        self::assertSame([false, true], $this->deletableOf([$ferniteAlloyWithStock, $ferniteAlloyWithoutStock]));
    }

    public function testBothJobsOfASplitRootStepAreDeletable(): void
    {
        $project = $this->ferniteCarbideProject(runs: 4);
        $rootJobs = array_map(
            fn (int $splitIndex): IndustryProjectStep => $this->stepOf($project, self::FERNITE_CARBIDE, 'reaction', runs: 2, quantity: 20_000, depth: 0)
                ->setSplitGroupId(self::FERNITE_CARBIDE_SPLIT_GROUP)->setSplitIndex($splitIndex)->setTotalGroupRuns(4),
            [0, 1],
        );
        $this->stepOf($project, self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1);

        self::assertSame([true, true], $this->deletableOf($rootJobs));
    }

    public function testProductAddedFromATypeAndCopyStepAreDeletable(): void
    {
        $project = $this->ferniteCarbideProject(runs: 4);
        $this->stepOf($project, self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0);
        $phenolicComposites = $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 4, quantity: 8_800, depth: 0);
        $hydrogenFuelBlockCopy = $this->stepOf($project, self::HYDROGEN_FUEL_BLOCK_TYPE_ID, 'copy', runs: 1, quantity: 1, depth: 2);

        self::assertSame([true, true], $this->deletableOf([$phenolicComposites, $hydrogenFuelBlockCopy]));
    }

    public function testStepInManualModeWithoutMatchedJobIsDeletable(): void
    {
        $project = $this->ferniteCarbideProject(runs: 4);
        $this->stepOf($project, self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0);
        $ferniteAlloy = $this->stepOf($project, self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1)->setJobMatchMode('manual');

        self::assertSame([true], $this->deletableOf([$ferniteAlloy]));
    }

    public function testStepWhoseMaterialStepWouldBeLeftWithoutAnyOtherConsumerIsNotDeletable(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $quantumMicroprocessor = $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbide = $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        $oxygenFuelBlock = $this->stepOf($project, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2);

        self::assertSame([false, false, true], $this->deletableOf([$quantumMicroprocessor, $titaniumCarbide, $oxygenFuelBlock]));
    }

    public function testStepsWhoseMaterialStepKeepsAnotherConsumerAreDeletable(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbide = $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        $phenolicComposites = $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173, depth: 1);
        $oxygenFuelBlock = $this->stepOf($project, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 10, depth: 2);

        self::assertSame([true, true, true], $this->deletableOf([$titaniumCarbide, $phenolicComposites, $oxygenFuelBlock]));
    }

    public function testBothJobsOfASplitStepWhoseMaterialStepHasNoOtherConsumerAreDeletable(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbideJobs = array_map(
            fn (int $splitIndex): IndustryProjectStep => $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 245, depth: 1)
                ->setSplitGroupId(self::TITANIUM_CARBIDE_SPLIT_GROUP)->setSplitIndex($splitIndex)->setTotalGroupRuns(2),
            [0, 1],
        );
        $this->stepOf($project, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 10, depth: 2);

        self::assertSame([true, true], $this->deletableOf($titaniumCarbideJobs));
    }

    public function testNoStepOfAProjectWithABlueprintMissingFromTheSdeIsDeletable(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $steps = [
            $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0),
            $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1),
            $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173, depth: 1),
            $this->stepOf($project, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 10, depth: 2),
        ];
        $mapper = $this->mapper($this->materialRepositoryOnGoldenSdeWithoutTheBlueprintOf(self::QUANTUM_MICROPROCESSOR));

        self::assertSame(
            [false, false, false, false],
            array_map(static fn (IndustryProjectStep $step): bool => $mapper->stepToResource($step)->deletable, $steps),
        );
    }

    public function testMaterialsOfAllTheStepsOfAProjectAreReadFromTheSdeOnce(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $steps = [
            $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0),
            $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1),
            $this->stepOf($project, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2),
        ];
        $goldenMaterialRepository = $this->materialRepositoryOnGoldenSde();
        $materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $materialRepository->expects(self::once())->method('findMaterialEntitiesForBlueprints')->willReturnCallback($goldenMaterialRepository->findMaterialEntitiesForBlueprints(...));
        $mapper = $this->mapper($materialRepository);

        foreach ($steps as $step) {
            $mapper->stepToResource($step);
        }
    }

    private function deletableOf(array $steps): array
    {
        $mapper = $this->mapper($this->materialRepositoryOnGoldenSde());

        return array_map(static fn (IndustryProjectStep $step): bool => $mapper->stepToResource($step)->deletable, $steps);
    }

    private function ferniteCarbideProject(int $runs): IndustryProject
    {
        return (new IndustryProject())->setUser(new User())->setProductTypeId(self::FERNITE_CARBIDE)->setRuns($runs);
    }

    private function quantumMicroprocessorProject(): IndustryProject
    {
        return (new IndustryProject())->setUser(new User())->setProductTypeId(self::QUANTUM_MICROPROCESSOR)->setRuns(32);
    }

    private function alloyJobOf(IndustryProject $project, int $splitIndex, int $runs): IndustryProjectStep
    {
        return $this->stepOf($project, self::FERNITE_ALLOY, 'reaction', runs: $runs, quantity: $runs * 200, depth: 1)
            ->setSplitGroupId(self::FERNITE_ALLOY_SPLIT_GROUP)->setSplitIndex($splitIndex)->setTotalGroupRuns(5);
    }

    private function stepOf(IndustryProject $project, int $productTypeId, string $activityType, int $runs, int $quantity, int $depth): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setSortOrder($depth)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Tatara Delve')->setStructureType('tatara')->setSecurityType('nullsec'));
        $project->addStep($step);

        return $step;
    }

    private function mapper(IndustryActivityMaterialRepository $materialRepository): IndustryResourceMapper
    {
        return self::autowired(IndustryResourceMapper::class, [
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(IndustryActivityRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
            $materialRepository,
        ]);
    }
}
