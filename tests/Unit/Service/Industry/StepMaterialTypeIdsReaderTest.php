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
    private const int OXYGEN_FUEL_BLOCK = 4312;

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
