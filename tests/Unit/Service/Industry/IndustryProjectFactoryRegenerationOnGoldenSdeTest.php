<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryProjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectFactory::class)]
final class IndustryProjectFactoryRegenerationOnGoldenSdeTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const array FUEL_BLOCKS = [self::HYDROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK];
    private const int NOMAD = 28846;
    private const int ME_LEVEL = 10;
    private const int TE_LEVEL = 20;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;

    private IndustryProjectFactory $factory;

    protected function setUp(): void
    {
        $this->goldenBlueprintsHaveTheirCopyActivity = true;
        $bonusService = new TataraAndRaitaruBonusService(
            static fn (): bool => false,
            static fn (int $typeId): bool => false,
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
        $this->factory = $this->projectFactoryOnGoldenSde(
            $bonusService,
            $this->calculationServiceOnGoldenSde($bonusService, $this->createStub(IndustryStructureConfigRepository::class)),
        );
    }

    public function testRegenerationKeepsTheSplitIndexAndTheTotalGroupRunsOfEachJobOfASplitGroupWithAJobOfFixedRuns(): void
    {
        $project = $this->factory->createProject(new User(), self::NOMAD, 2, self::ME_LEVEL, self::DEFAULT_MAX_JOB_DURATION_DAYS, self::TE_LEVEL);
        $hydrogenFuelBlockJobs = $this->hydrogenFuelBlockJobsOf($project);
        $hydrogenFuelBlockJobs[1]->setRuns(170)->setRunsFixed(true);
        $splitGroupId = $hydrogenFuelBlockJobs[0]->getSplitGroupId();

        $this->factory->regenerateSteps($project);

        self::assertSame(
            [[158, 6320, 0, 642, $splitGroupId], [170, 6800, 1, 642, $splitGroupId], [157, 6280, 2, 642, $splitGroupId], [157, 6280, 3, 642, $splitGroupId]],
            array_map(
                static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->getSplitIndex(), $job->getTotalGroupRuns(), $job->getSplitGroupId()],
                $this->hydrogenFuelBlockJobsOf($project),
            ),
        );
        self::assertNotNull($splitGroupId);
    }

    public function testRegenerationKeepsTheStructureOfEachCopyJob(): void
    {
        $project = $this->factory->createProject(new User(), self::NOMAD, 1, self::ME_LEVEL, self::DEFAULT_MAX_JOB_DURATION_DAYS, self::TE_LEVEL);
        $copyStructure = (new IndustryStructureConfig())->setName('Azbel')->setStructureType('azbel')->setSecurityType('lowsec')->setRigs([]);
        $this->hydrogenFuelBlockCopyStepsOf($project)[0]->setStructureConfig($copyStructure);

        $this->factory->regenerateSteps($project);

        self::assertSame([$copyStructure, null], array_map(
            static fn (IndustryProjectStep $copyStep): ?IndustryStructureConfig => $copyStep->getStructureConfig(),
            $this->hydrogenFuelBlockCopyStepsOf($project),
        ));
    }

    public function testCopyStepsOfTheSameDepthAreOrderedByTheNameOfTheirProduct(): void
    {
        $project = $this->factory->createProject(new User(), self::NOMAD, 1, self::ME_LEVEL, self::DEFAULT_MAX_JOB_DURATION_DAYS, self::TE_LEVEL);

        $fuelBlockCopySteps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getActivityType() === 'copy' && \in_array($step->getProductTypeId(), self::FUEL_BLOCKS, true),
        ));
        usort($fuelBlockCopySteps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        self::assertSame(
            [self::HELIUM_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK, self::OXYGEN_FUEL_BLOCK],
            array_map(static fn (IndustryProjectStep $step): int => $step->getProductTypeId(), $fuelBlockCopySteps),
        );
        self::assertSame([5, 5, 5, 5, 5], array_map(static fn (IndustryProjectStep $step): int => $step->getDepth(), $fuelBlockCopySteps));
    }

    private function hydrogenFuelBlockCopyStepsOf(IndustryProject $project): array
    {
        $copySteps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === self::HYDROGEN_FUEL_BLOCK && $step->getActivityType() === 'copy',
        ));
        usort($copySteps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $copySteps;
    }

    private function hydrogenFuelBlockJobsOf(IndustryProject $project): array
    {
        $jobs = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === self::HYDROGEN_FUEL_BLOCK && $step->getActivityType() !== 'copy',
        ));
        usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $jobs;
    }
}
