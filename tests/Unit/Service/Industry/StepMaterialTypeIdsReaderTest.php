<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Service\Industry\StepMaterialsMissingFromSdeException;
use App\Service\Industry\StepMaterialTypeIdsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StepMaterialTypeIdsReader::class)]
#[Group('issue-202')]
final class StepMaterialTypeIdsReaderTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const array MATERIALS_OF_QUANTUM_MICROPROCESSOR = [self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES, 16681, 33362];
    private const array MATERIALS_OF_TITANIUM_CARBIDE = [self::OXYGEN_FUEL_BLOCK, 16654, 16658];
    private const array MATERIALS_OF_PHENOLIC_COMPOSITES = [self::OXYGEN_FUEL_BLOCK, 16658, 16663, 17959];
    private const array MATERIALS_OF_OXYGEN_FUEL_BLOCK = [44, 3683, 3689, 9832, 9848, 16272, 16273, 16275, 17887];

    private array $blueprintTypeIdsOfEachRead = [];

    public function testMaterialsOfEachProductionStepAreTheMaterialsOfItsBlueprintInTheSde(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173, depth: 1);
        $reader = $this->readerOn($this->materialRepositoryOnGoldenSde());

        self::assertSame(
            [self::MATERIALS_OF_QUANTUM_MICROPROCESSOR, self::MATERIALS_OF_TITANIUM_CARBIDE, self::MATERIALS_OF_OXYGEN_FUEL_BLOCK, self::MATERIALS_OF_PHENOLIC_COMPOSITES],
            $this->outcomesOf($reader, $project->getSteps()->getValues()),
        );
    }

    public function testStepWhoseBlueprintIsMissingFromTheSdeRaisesAnError(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $titaniumCarbide = $project->getSteps()->getValues()[1];
        $reader = $this->readerOn($this->materialRepositoryOnGoldenSdeWithoutTheBlueprintOf(self::TITANIUM_CARBIDE));

        self::assertSame([StepMaterialsMissingFromSdeException::class], $this->outcomesOf($reader, [$titaniumCarbide]));
    }

    public function testEveryStepOfAProjectWithAnotherBlueprintMissingFromTheSdeRaisesAnErrorAtEachRead(): void
    {
        $project = $this->quantumMicroprocessorProject();
        [$quantumMicroprocessor, $titaniumCarbide, $oxygenFuelBlock] = $project->getSteps()->getValues();
        $reader = $this->readerOn($this->materialRepositoryOnGoldenSdeWithoutTheBlueprintOf(self::OXYGEN_FUEL_BLOCK));

        self::assertSame(
            array_fill(0, 4, StepMaterialsMissingFromSdeException::class),
            $this->outcomesOf($reader, [$quantumMicroprocessor, $titaniumCarbide, $quantumMicroprocessor, $oxygenFuelBlock]),
        );
    }

    public function testStepsOfASecondProjectSharingTheBlueprintsOfTheFirstAreAnsweredWithoutReadingTheSdeAgain(): void
    {
        $firstProject = $this->quantumMicroprocessorProject();
        $secondProject = (new IndustryProject())->setUser(new User())->setProductTypeId(self::TITANIUM_CARBIDE)->setRuns(3);
        $this->stepOf($secondProject, self::TITANIUM_CARBIDE, 'reaction', runs: 3, quantity: 30_000, depth: 0);
        $this->stepOf($secondProject, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 15, depth: 1);
        $reader = $this->readerOn($this->materialRepositoryOnGoldenSdeRecordingEachRead());

        $outcomes = [
            ...$this->outcomesOf($reader, [$firstProject->getSteps()->getValues()[0]]),
            ...$this->outcomesOf($reader, $secondProject->getSteps()->getValues()),
        ];

        self::assertSame(
            [
                [self::MATERIALS_OF_QUANTUM_MICROPROCESSOR, self::MATERIALS_OF_TITANIUM_CARBIDE, self::MATERIALS_OF_OXYGEN_FUEL_BLOCK],
                [$this->blueprintTypeIdsOf([self::OXYGEN_FUEL_BLOCK, self::QUANTUM_MICROPROCESSOR, self::TITANIUM_CARBIDE])],
            ],
            [$outcomes, $this->blueprintTypeIdsOfEachRead],
        );
    }

    public function testStepOfANewBlueprintIsReadAloneWithoutReadingTheKnownBlueprintsAgain(): void
    {
        $project = $this->quantumMicroprocessorProject();
        $reader = $this->readerOn($this->materialRepositoryOnGoldenSdeRecordingEachRead());
        $this->outcomesOf($reader, [$project->getSteps()->getValues()[0]]);
        $phenolicComposites = $this->stepOf($project, self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 173, depth: 1);

        self::assertSame(
            [
                [self::MATERIALS_OF_PHENOLIC_COMPOSITES],
                [
                    $this->blueprintTypeIdsOf([self::OXYGEN_FUEL_BLOCK, self::QUANTUM_MICROPROCESSOR, self::TITANIUM_CARBIDE]),
                    $this->blueprintTypeIdsOf([self::PHENOLIC_COMPOSITES]),
                ],
            ],
            [$this->outcomesOf($reader, [$phenolicComposites]), $this->blueprintTypeIdsOfEachRead],
        );
    }

    private function outcomesOf(StepMaterialTypeIdsReader $reader, array $steps): array
    {
        return array_map(
            static function (IndustryProjectStep $step) use ($reader): array|string {
                try {
                    $materialTypeIds = $reader->materialTypeIdsOf($step);
                } catch (StepMaterialsMissingFromSdeException) {
                    return StepMaterialsMissingFromSdeException::class;
                }

                return $materialTypeIds;
            },
            $steps,
        );
    }

    private function blueprintTypeIdsOf(array $productTypeIds): array
    {
        return array_map(static fn (int $productTypeId): int => $productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, $productTypeIds);
    }

    private function materialRepositoryOnGoldenSdeRecordingEachRead(): IndustryActivityMaterialRepository
    {
        $goldenMaterialRepository = $this->materialRepositoryOnGoldenSde();
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialEntitiesForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds) use ($goldenMaterialRepository): array {
                $readBlueprintTypeIds = array_values(array_unique($blueprintTypeIds));
                sort($readBlueprintTypeIds);
                $this->blueprintTypeIdsOfEachRead[] = $readBlueprintTypeIds;

                return $goldenMaterialRepository->findMaterialEntitiesForBlueprints($blueprintTypeIds, $activityIds);
            },
        );

        return $materialRepository;
    }

    private function readerOn(IndustryActivityMaterialRepository $materialRepository): StepMaterialTypeIdsReader
    {
        return self::autowired(StepMaterialTypeIdsReader::class, [$materialRepository]);
    }

    private function quantumMicroprocessorProject(): IndustryProject
    {
        $project = (new IndustryProject())->setUser(new User())->setProductTypeId(self::QUANTUM_MICROPROCESSOR)->setRuns(32);
        $this->stepOf($project, self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0);
        $this->stepOf($project, self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1);
        $this->stepOf($project, self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2);

        return $project;
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
            ->setSortOrder($depth);
        $project->addStep($step);

        return $step;
    }
}
