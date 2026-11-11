<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Industry\Application\PlannedJobTimeModifier;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Service\Industry\Project\PlanningUnscheduled;
use App\Service\Industry\Project\ProjectPlanning;
use App\Service\Industry\Project\ProjectPlanningBuilder;
use App\Service\Industry\Project\ProjectPlanningInputLoader;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectPlanningInputLoader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-706')]
final class ProjectPlanningInputLoaderStockCoveredLineTest extends TestCase
{
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_ALLOY = 16656;
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8057';

    public function testPlanningOfAProjectIgnoresTheLineCoveredByItsStockAtZeroRuns(): void
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $project = (new IndustryProject())->setOwner($owner);
        $coveredHydrogenFuelBlock = (new IndustryProjectBomLine())
            ->setProject($project)
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK)
            ->setTypeName('Hydrogen Fuel Block')
            ->setRequiredQuantity(15)
            ->setFulfilledQuantity(20)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('manufacturing')
            ->setMeLevel(10)
            ->setTeLevel(20)
            ->setRuns(0);
        $ferniteAlloy = (new IndustryProjectBomLine())
            ->setProject($project)
            ->setTypeId(self::FERNITE_ALLOY)
            ->setTypeName('Fernite Alloy')
            ->setRequiredQuantity(200)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('reaction')
            ->setMeLevel(0)
            ->setTeLevel(0)
            ->setRuns(1);
        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findBy')->willReturn([$coveredHydrogenFuelBlock, $ferniteAlloy]);
        $loader = new ProjectPlanningInputLoader(
            $bomLineRepository,
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(CharacterRepository::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            InMemoryBlueprintCatalog::fromSdeFixture(),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(IndustryActivitySkillRepository::class),
            new InMemoryUserProductionStructures([self::OWNER_ID => []]),
            new PlannedJobTimeModifier(new InMemoryRigCategories()),
        );

        $failure = null;
        $planning = null;
        try {
            $planning = (new ProjectPlanningBuilder())->build($loader->load($project), new \DateTimeImmutable('2026-11-11T08:00:00+00:00'));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        self::assertNull($failure);
        self::assertInstanceOf(ProjectPlanning::class, $planning);
        self::assertSame(
            ['Fernite Alloy'],
            array_map(static fn (PlanningUnscheduled $unscheduled): string => $unscheduled->typeName, $planning->unscheduled),
        );
    }
}
