<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
#[Group('issue-202')]
final class IndustryProjectRefusalToDeleteStepTest extends TestCase
{
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const array MATERIAL_TYPE_IDS_BY_PRODUCT = [
        self::QUANTUM_MICROPROCESSOR => [self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES, 16681, 33362],
        self::TITANIUM_CARBIDE => [self::OXYGEN_FUEL_BLOCK, 16654, 16658],
        self::PHENOLIC_COMPOSITES => [self::OXYGEN_FUEL_BLOCK, 16658, 16663, 17959],
        self::OXYGEN_FUEL_BLOCK => [44, 3683, 3689, 9832, 9848, 16272, 16273, 16275, 17887],
        self::FERNITE_CARBIDE => [self::HYDROGEN_FUEL_BLOCK, self::FERNITE_ALLOY, 16660],
        self::FERNITE_ALLOY => [self::HYDROGEN_FUEL_BLOCK, 16639, 16642],
        self::HYDROGEN_FUEL_BLOCK => [44, 3683, 3689, 9832, 9848, 16272, 16273, 16275, 17889],
    ];
    private const string LINKED_TO_AN_ESI_JOB = 'Cannot delete a step linked to an ESI job';
    private const string LAST_STEP_OF_THE_PROJECT_PRODUCT = 'Cannot delete the last step of the project product';
    private const string HOLDING_PURCHASES_STOCK_OR_A_PURCHASED_MARK = 'Cannot delete a step holding purchases, stock or a purchased mark';
    private const string MATERIAL_STEPS_WITHOUT_ANY_OTHER_CONSUMER = 'Cannot delete a step whose material steps have no other consumer; delete them first';

    private IndustryProject $project;

    public function testStepWhoseMaterialStepWouldBeLeftWithoutAnyOtherConsumerIsRefused(): void
    {
        $steps = $this->quantumMicroprocessorProject();

        self::assertSame(
            [self::LAST_STEP_OF_THE_PROJECT_PRODUCT, self::MATERIAL_STEPS_WITHOUT_ANY_OTHER_CONSUMER, null],
            $this->refusalsToDelete($steps),
        );
    }

    public function testStepsWhoseMaterialStepKeepsAnotherConsumerAreNotRefused(): void
    {
        [, $titaniumCarbide, $oxygenFuelBlock] = $this->quantumMicroprocessorProject();
        $phenolicComposites = $this->stepOf(self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173, depth: 1);
        $oxygenFuelBlock->setQuantity(10);

        self::assertSame([null, null, null], $this->refusalsToDelete([$titaniumCarbide, $phenolicComposites, $oxygenFuelBlock]));
    }

    public function testOnlyStepOfAMaterialWhoseOwnMaterialStepKeepsAnotherConsumerIsNotRefused(): void
    {
        $this->project = $this->projectOf(self::FERNITE_CARBIDE, runs: 4);
        $this->stepOf(self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0);
        $ferniteAlloy = $this->stepOf(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1);
        $this->stepOf(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 30, depth: 2);

        self::assertSame([null], $this->refusalsToDelete([$ferniteAlloy]));
    }

    public function testJobsOfASplitStepWhoseMaterialStepHasNoOtherConsumerAreNotRefused(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbideJobs = array_map(
            fn (int $splitIndex): IndustryProjectStep => $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 245, depth: 1)
                ->setSplitGroupId('titanium-carbide')->setSplitIndex($splitIndex)->setTotalGroupRuns(2),
            [0, 1],
        );
        $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 10, depth: 2);

        self::assertSame([null, null], $this->refusalsToDelete($titaniumCarbideJobs));
    }

    #[DataProvider('stockAndPurchasedMarkOfTheMaterialStep')]
    public function testMaterialStepHoldingStockOrAPurchasedMarkStillNeedsItsLastConsumer(int $inStockQuantity, bool $purchased): void
    {
        [, $titaniumCarbide, $oxygenFuelBlock] = $this->quantumMicroprocessorProject();
        $oxygenFuelBlock->setInStockQuantity($inStockQuantity)->setPurchased($purchased);

        self::assertSame([self::MATERIAL_STEPS_WITHOUT_ANY_OTHER_CONSUMER], $this->refusalsToDelete([$titaniumCarbide]));
    }

    public static function stockAndPurchasedMarkOfTheMaterialStep(): iterable
    {
        yield 'purchased mark' => [0, true];
        yield 'stock' => [5, false];
    }

    public function testProductAddedFromATypeIsRefusedOnlyWhenItHasAMaterialStep(): void
    {
        $this->project = $this->projectOf(self::FERNITE_CARBIDE, runs: 4);
        $this->stepOf(self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0);
        $phenolicComposites = $this->stepOf(self::PHENOLIC_COMPOSITES, 'reaction', runs: 4, quantity: 8_800, depth: 0);
        $refusalWithoutMaterialStep = $this->project->refusalToDeleteStep($phenolicComposites, $this->materialTypeIdsOf(...));
        $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 20, depth: 1);

        self::assertSame(
            [null, self::MATERIAL_STEPS_WITHOUT_ANY_OTHER_CONSUMER],
            [$refusalWithoutMaterialStep, $this->project->refusalToDeleteStep($phenolicComposites, $this->materialTypeIdsOf(...))],
        );
    }

    public function testSingleStepHoldingStockIsRefusedForItsStockBeforeItsMaterialSteps(): void
    {
        [, $titaniumCarbide] = $this->quantumMicroprocessorProject();
        $titaniumCarbide->setInStockQuantity(100);

        self::assertSame([self::HOLDING_PURCHASES_STOCK_OR_A_PURCHASED_MARK], $this->refusalsToDelete([$titaniumCarbide]));
    }

    public function testStepLinkedToAnEsiJobIsRefusedForItsMatchedJobBeforeItsMaterialSteps(): void
    {
        [, $titaniumCarbide] = $this->quantumMicroprocessorProject();
        $titaniumCarbide->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_202_501)->setRuns(1)->setStatus('active'));

        self::assertSame([self::LINKED_TO_AN_ESI_JOB], $this->refusalsToDelete([$titaniumCarbide]));
    }

    public function testCopyOfAMaterialIsNotAMaterialStep(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbide = $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        $oxygenFuelBlockCopy = $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'copy', runs: 1, quantity: 1, depth: 2);

        self::assertSame([null, null], $this->refusalsToDelete([$titaniumCarbide, $oxygenFuelBlockCopy]));
    }

    public function testDeletedCopyIsNotRefusedWhenTheSdeGivesItsBlueprintMaterials(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbideCopy = $this->stepOf(self::TITANIUM_CARBIDE, 'copy', runs: 1, quantity: 1, depth: 1);
        $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2);

        self::assertSame([null], $this->refusalsToDelete([$titaniumCarbideCopy], $this->materialTypeIdsOfTheBlueprintOf(...)));
    }

    public function testCopyIsNotAConsumerWhenTheSdeGivesItsBlueprintMaterials(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbide = $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        $this->stepOf(self::TITANIUM_CARBIDE, 'copy', runs: 1, quantity: 1, depth: 1);
        $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2);

        self::assertSame(
            [self::MATERIAL_STEPS_WITHOUT_ANY_OTHER_CONSUMER],
            $this->refusalsToDelete([$titaniumCarbide], $this->materialTypeIdsOfTheBlueprintOf(...)),
        );
    }

    public function testCopyOfAMaterialIsNotAMaterialStepWhenTheSdeGivesItsBlueprintMaterials(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbide = $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'copy', runs: 1, quantity: 1, depth: 2);

        self::assertSame([null], $this->refusalsToDelete([$titaniumCarbide], $this->materialTypeIdsOfTheBlueprintOf(...)));
    }

    public function testOnlyProductionStepOfTheProjectProductBesideACopyOfItIsRefusedAsTheLastStepOfTheProjectProduct(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $quantumMicroprocessor = $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'copy', runs: 32, quantity: 1, depth: 0);
        $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);

        self::assertSame(
            [self::LAST_STEP_OF_THE_PROJECT_PRODUCT],
            $this->refusalsToDelete([$quantumMicroprocessor], $this->materialTypeIdsOfTheBlueprintOf(...)),
        );
    }

    public function testJobOfTheProjectProductBesideACopyOfItAndAnotherJobOfItIsNotRefused(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $firstQuantumMicroprocessorJob = $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 20, quantity: 20, depth: 0)
            ->setSplitGroupId('quantum-microprocessor')->setSplitIndex(0)->setTotalGroupRuns(32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 12, quantity: 12, depth: 0)
            ->setSplitGroupId('quantum-microprocessor')->setSplitIndex(1)->setTotalGroupRuns(32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'copy', runs: 32, quantity: 1, depth: 0);
        $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);

        self::assertSame([null], $this->refusalsToDelete([$firstQuantumMicroprocessorJob], $this->materialTypeIdsOfTheBlueprintOf(...)));
    }

    public function testOnlyConsumerOfASplitMaterialStepIsRefused(): void
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);
        $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $titaniumCarbide = $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        foreach ([[0, 1, 40], [1, 2, 80]] as [$splitIndex, $runs, $quantity]) {
            $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: $runs, quantity: $quantity, depth: 2)
                ->setSplitGroupId('oxygen-fuel-block')->setSplitIndex($splitIndex)->setTotalGroupRuns(3);
        }

        self::assertSame([self::MATERIAL_STEPS_WITHOUT_ANY_OTHER_CONSUMER], $this->refusalsToDelete([$titaniumCarbide]));
    }

    private function refusalsToDelete(array $steps, ?\Closure $materialTypeIdsOf = null): array
    {
        $materialTypeIdsOf ??= $this->materialTypeIdsOf(...);

        return array_map(
            fn (IndustryProjectStep $step): ?string => $this->project->refusalToDeleteStep($step, $materialTypeIdsOf),
            $steps,
        );
    }

    private function materialTypeIdsOf(IndustryProjectStep $step): array
    {
        if ($step->getActivityType() === 'copy') {
            return [];
        }

        return $this->materialTypeIdsOfTheBlueprintOf($step);
    }

    private function materialTypeIdsOfTheBlueprintOf(IndustryProjectStep $step): array
    {
        return self::MATERIAL_TYPE_IDS_BY_PRODUCT[$step->getProductTypeId()];
    }

    private function quantumMicroprocessorProject(): array
    {
        $this->project = $this->projectOf(self::QUANTUM_MICROPROCESSOR, runs: 32);

        return [
            $this->stepOf(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0),
            $this->stepOf(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1),
            $this->stepOf(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2),
        ];
    }

    private function projectOf(int $productTypeId, int $runs): IndustryProject
    {
        return (new IndustryProject())->setOwner(new User())->setProductTypeId($productTypeId)->setRuns($runs);
    }

    private function stepOf(int $productTypeId, string $activityType, int $runs, int $quantity, int $depth): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setSortOrder($depth);
        $this->project->addStep($step);

        return $step;
    }
}
