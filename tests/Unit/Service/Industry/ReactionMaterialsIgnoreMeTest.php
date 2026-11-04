<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Entity\Sde\IndustryActivityProduct;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\ProductionStepNeedsResolver;
use App\Service\Industry\SplitGroupMaterialNeeds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionStepNeedsResolver::class)]
#[CoversClass(IndustryStepCalculator::class)]
#[CoversClass(SplitGroupMaterialNeeds::class)]
#[CoversClass(IndustryProjectFactory::class)]
#[Group('issue-250')]
final class ReactionMaterialsIgnoreMeTest extends TestCase
{
    use BuildsShoppingListOnGoldenSde;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int SCANDIUM = 16639;
    private const int VANADIUM = 16642;
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int STORED_ME_OF_A_REACTION_STEP = 10;
    private const int FERNITE_ALLOY_FOR_TEN_RUNS_OF_FERNITE_CARBIDE_WITH_THE_RIG = 974;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;

    private User $pilot;

    private bool $pilotOwnsTataraAndRaitaru = true;

    protected function setUp(): void
    {
        $this->pilot = new User();
    }

    public function testShoppingListOfAReactionStepAtMeTenBuysTheMaterialsOfMeZeroWithTheRigBonus(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_ALLOY, 10, 10);
        self::addStep($project, self::FERNITE_ALLOY, 10, 0, 'reaction', self::STORED_ME_OF_A_REACTION_STEP);

        $quantities = self::quantitiesByTypeId($this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['materials']);

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 49, self::SCANDIUM => 974, self::VANADIUM => 974], $quantities);
    }

    public function testShoppingListOfAReactionStepAtMeZeroBuysTheMaterialsWithTheRigBonus(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_ALLOY, 10, 0);
        self::addStep($project, self::FERNITE_ALLOY, 10, 0, 'reaction', 0);

        $quantities = self::quantitiesByTypeId($this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['materials']);

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 49, self::SCANDIUM => 974, self::VANADIUM => 974], $quantities);
    }

    public function testCascadeFromAReactionStepAtMeTenNeedsTheMaterialStepOfMeZero(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_CARBIDE, 10, 10);
        self::addStep($project, self::FERNITE_CARBIDE, 10, 0, 'reaction', self::STORED_ME_OF_A_REACTION_STEP);
        $ferniteAlloy = self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0);

        $this->stepCalculator()->recalculateStepQuantities($project);

        self::assertSame([5, self::FERNITE_ALLOY_FOR_TEN_RUNS_OF_FERNITE_CARBIDE_WITH_THE_RIG], [$ferniteAlloy->getRuns(), $ferniteAlloy->getQuantity()]);
    }

    public function testCascadeFromAReactionStepAtMeZeroNeedsTheMaterialStepWithTheRigBonus(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_CARBIDE, 10, 0);
        self::addStep($project, self::FERNITE_CARBIDE, 10, 0, 'reaction', 0);
        $ferniteAlloy = self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0);

        $this->stepCalculator()->recalculateStepQuantities($project);

        self::assertSame([5, self::FERNITE_ALLOY_FOR_TEN_RUNS_OF_FERNITE_CARBIDE_WITH_THE_RIG], [$ferniteAlloy->getRuns(), $ferniteAlloy->getQuantity()]);
    }

    public function testCascadeFromAManufacturingStepAtMeTenStillAppliesTheMe(): void
    {
        $this->pilotOwnsTataraAndRaitaru = false;
        $project = self::projectOf($this->pilot, self::QUANTUM_MICROPROCESSOR, 10, 10);
        self::addStep($project, self::QUANTUM_MICROPROCESSOR, 10, 0, 'manufacturing', 10);
        $titaniumCarbide = self::addStep($project, self::TITANIUM_CARBIDE, 1, 1, 'reaction', 0);

        $this->stepCalculator()->recalculateStepQuantities($project);

        self::assertSame([1, 153], [$titaniumCarbide->getRuns(), $titaniumCarbide->getQuantity()]);
    }

    public function testChangeOfJobsOfAReactionStepAtMeTenNeedsTheMaterialStepOfMeZero(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_CARBIDE, 20, 10);
        $ferniteCarbide = self::addStep($project, self::FERNITE_CARBIDE, 10, 0, 'reaction', self::STORED_ME_OF_A_REACTION_STEP);
        $ferniteAlloy = self::addStep($project, self::FERNITE_ALLOY, 5, 1, 'reaction', 0, self::FERNITE_ALLOY_FOR_TEN_RUNS_OF_FERNITE_CARBIDE_WITH_THE_RIG);

        $this->splitGroupMaterialNeeds()->applyChangeOfJobs($project, $ferniteCarbide, [10], [10, 10]);

        self::assertSame([10, 2 * self::FERNITE_ALLOY_FOR_TEN_RUNS_OF_FERNITE_CARBIDE_WITH_THE_RIG], [$ferniteAlloy->getRuns(), $ferniteAlloy->getQuantity()]);
    }

    public function testChangeOfJobsOfAManufacturingStepAtMeTenStillAppliesTheMe(): void
    {
        $this->pilotOwnsTataraAndRaitaru = false;
        $project = self::projectOf($this->pilot, self::QUANTUM_MICROPROCESSOR, 20, 10);
        $quantumMicroprocessor = self::addStep($project, self::QUANTUM_MICROPROCESSOR, 10, 0, 'manufacturing', 10);
        $titaniumCarbide = self::addStep($project, self::TITANIUM_CARBIDE, 1, 1, 'reaction', 0, 153);

        $this->splitGroupMaterialNeeds()->applyChangeOfJobs($project, $quantumMicroprocessor, [10], [10, 10]);

        self::assertSame([1, 306], [$titaniumCarbide->getRuns(), $titaniumCarbide->getQuantity()]);
    }

    public function testRegenerationOfAProjectWhoseReactionStepIsAtMeTenPlansTheMaterialStepOfMeZero(): void
    {
        $factory = $this->projectFactoryOnGoldenSde($this->pilotBonusService(), $this->calculationServiceOnGoldenSde($this->pilotBonusService(), $this->pilotStructureConfigRepository()));
        $project = $factory->createProject($this->pilot, self::FERNITE_CARBIDE, 10, 10, self::DEFAULT_MAX_JOB_DURATION_DAYS, 20);
        $this->onlyStepOf($project, self::FERNITE_CARBIDE)->setMeLevel(self::STORED_ME_OF_A_REACTION_STEP);

        $factory->regenerateSteps($project);

        $ferniteAlloy = $this->onlyStepOf($project, self::FERNITE_ALLOY);
        self::assertSame([5, self::FERNITE_ALLOY_FOR_TEN_RUNS_OF_FERNITE_CARBIDE_WITH_THE_RIG], [$ferniteAlloy->getRuns(), $ferniteAlloy->getQuantity()]);
    }

    private function pilotBonusService(): IndustryBonusService
    {
        return new TataraAndRaitaruBonusService(
            fn (): bool => $this->pilotOwnsTataraAndRaitaru,
            static fn (int $typeId): bool => true,
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }

    private function stepCalculator(): IndustryStepCalculator
    {
        return new IndustryStepCalculator(
            $this->calculationServiceOnGoldenSde($this->pilotBonusService(), $this->pilotStructureConfigRepository()),
            $this->materialRepositoryOnGoldenSde(),
            $this->productRepositoryOnGoldenSde(),
            $this->entityManagerWithoutRows(),
        );
    }

    private function splitGroupMaterialNeeds(): SplitGroupMaterialNeeds
    {
        return new SplitGroupMaterialNeeds(
            $this->calculationServiceOnGoldenSde($this->pilotBonusService(), $this->pilotStructureConfigRepository()),
            $this->materialRepositoryOnGoldenSde(),
            $this->productRepositoryReadingOneProductOfTheGoldenSde(),
        );
    }

    private function productRepositoryReadingOneProductOfTheGoldenSde(): IndustryActivityProductRepository
    {
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria): ?IndustryActivityProduct {
                $productTypeId = $criteria['typeId'] - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
                $recipe = self::goldenRecipes()[$productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $criteria['activityId']) {
                    return null;
                }

                return self::activityProduct($productTypeId, $recipe);
            },
        );

        return $repository;
    }
}
