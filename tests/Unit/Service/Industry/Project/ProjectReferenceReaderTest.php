<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ProjectReference;
use App\Service\Industry\Project\ProjectReferenceReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-354')]
final class ProjectReferenceReaderTest extends TestCase
{
    private const int ISHTAR = 12005;
    private const int HECATE = 35683;
    private const int ISHTAR_UNITS = 100;
    private const float ISHTAR_TOTAL_COST = 2_000_000.0;
    private const float ISHTAR_UNIT_COST = 20_000.0;
    private const float ISHTAR_MARGIN_PERCENT = 12.0;

    private \DateTimeImmutable $now;
    private User $reader;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-07 12:00:00 UTC');
        $this->reader = new User();
    }

    public function testRealUnitCostAndMarginOfTheLastSingleProductProjectBecomeTheReference(): void
    {
        $project = $this->completedProject(self::ISHTAR, '-5 days', self::ISHTAR_UNITS);

        $references = $this->readWith([[$project, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)]]);

        self::assertCount(1, $references);
        self::assertSame(
            [self::ISHTAR, $project->getId()->toRfc4122(), self::ISHTAR_UNITS, self::ISHTAR_UNIT_COST, self::ISHTAR_MARGIN_PERCENT],
            [$references[0]->productTypeId, $references[0]->projectId, $references[0]->units, $references[0]->unitCost, $references[0]->marginPercent],
        );
        self::assertEquals($project->getCompletedAt(), $references[0]->completedAt);
    }

    public function testOnlyProjectsFinishedWithinTheLastMonthAreRequestedFromTheRepository(): void
    {
        $repository = $this->createMock(IndustryProjectParticipantRepository::class);
        $repository->expects(self::once())
            ->method('findAcceptedOnProjectsCompletedBetween')
            ->with($this->reader, $this->now->modify('-1 month'), $this->now)
            ->willReturn([]);

        (new ProjectReferenceReader($repository, $this->createStub(ProjectDistributionService::class)))->read($this->reader, $this->now);
    }

    public function testProjectWithSeveralProductsIsNeverAReference(): void
    {
        $project = $this->completedProject(self::ISHTAR, '-5 days', self::ISHTAR_UNITS);
        $project->addItem((new IndustryProjectProduct())->setTypeId(self::HECATE)->setTypeName('Hecate')->setRuns(1));

        $references = $this->readWith([[$project, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)]]);

        self::assertSame([], $references);
    }

    public function testMostRecentProjectOfAProductWinsAndAnOlderOneIsNeverUsed(): void
    {
        $recent = $this->completedProject(self::ISHTAR, '-5 days', self::ISHTAR_UNITS);
        $older = $this->completedProject(self::ISHTAR, '-10 days', self::ISHTAR_UNITS);

        $references = $this->readWith([
            [$recent, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)],
            [$older, $this->distribution(1_000_000.0, 50.0)],
        ]);

        self::assertCount(1, $references);
        self::assertSame($recent->getId()->toRfc4122(), $references[0]->projectId);
    }

    public function testEachProductHasItsOwnReference(): void
    {
        $ishtar = $this->completedProject(self::ISHTAR, '-5 days', self::ISHTAR_UNITS);
        $hecate = $this->completedProject(self::HECATE, '-8 days', 10);

        $references = $this->readWith([
            [$ishtar, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)],
            [$hecate, $this->distribution(500_000.0, 8.0)],
        ]);

        self::assertSame([self::ISHTAR, self::HECATE], array_map(static fn ($reference): int => $reference->productTypeId, $references));
        self::assertSame(50_000.0, $references[1]->unitCost);
    }

    public function testUnknownContributionValueKeepsTheUnitCostAndTheMarginUnknownWithoutFallingBackToAnOlderProject(): void
    {
        $recent = $this->completedProject(self::ISHTAR, '-5 days', self::ISHTAR_UNITS);
        $older = $this->completedProject(self::ISHTAR, '-10 days', self::ISHTAR_UNITS);

        $references = $this->readWith([
            [$recent, $this->distribution(null, null)],
            [$older, $this->distribution(1_000_000.0, 50.0)],
        ]);

        self::assertCount(1, $references);
        self::assertSame([self::ISHTAR_UNITS, null, null], [$references[0]->units, $references[0]->unitCost, $references[0]->marginPercent]);
    }

    public function testProjectWithoutRootBomLineHasUnknownUnitsAndUnitCostButKeepsItsMargin(): void
    {
        $project = $this->completedProject(self::ISHTAR, '-5 days', null);

        $references = $this->readWith([[$project, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)]]);

        self::assertCount(1, $references);
        self::assertSame([null, null, self::ISHTAR_MARGIN_PERCENT], [$references[0]->units, $references[0]->unitCost, $references[0]->marginPercent]);
    }

    public function testOnlyTheRootManufacturingLineOfTheProductGivesTheUnits(): void
    {
        $project = $this->completedProject(self::ISHTAR, '-5 days', self::ISHTAR_UNITS);
        $project->addBomItem($this->jobLine(self::ISHTAR, 'reaction', 5_000, parentTypeId: null));
        $project->addBomItem($this->jobLine(self::ISHTAR, 'manufacturing', 7_000, parentTypeId: self::HECATE));
        $project->addBomItem($this->jobLine(self::HECATE, 'manufacturing', 9_000, parentTypeId: null));

        $references = $this->readWith([[$project, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)]]);

        self::assertSame([self::ISHTAR_UNITS, self::ISHTAR_UNIT_COST], [$references[0]->units, $references[0]->unitCost]);
    }

    public function testProjectWithNoBuiltProductQuantityHasUnknownUnitCost(): void
    {
        $project = $this->completedProject(self::ISHTAR, '-5 days', 0);

        $references = $this->readWith([[$project, $this->distribution(self::ISHTAR_TOTAL_COST, self::ISHTAR_MARGIN_PERCENT)]]);

        self::assertSame([null, null], [$references[0]->units, $references[0]->unitCost]);
    }

    private function completedProject(int $productTypeId, string $completedAgo, ?int $rootUnits): IndustryProject
    {
        $project = (new IndustryProject())
            ->setStatus(ProjectStatus::Completed)
            ->setCompletedAt($this->now->modify($completedAgo));
        $project->addItem((new IndustryProjectProduct())->setTypeId($productTypeId)->setTypeName('Product')->setRuns(1));
        if (null !== $rootUnits) {
            $project->addBomItem($this->jobLine($productTypeId, 'manufacturing', $rootUnits, parentTypeId: null));
        }

        return $project;
    }

    private function jobLine(int $typeId, string $activityType, int $quantity, ?int $parentTypeId): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Product')
            ->setRequiredQuantity($quantity)
            ->setIsJob(true)
            ->setActivityType($activityType)
            ->setParentTypeId($parentTypeId);
    }

    private function distribution(?float $totalProjectCost, ?float $marginPercent): DistributionResult
    {
        return new DistributionResult(
            totalRevenue: 0.0,
            brokerFee: null,
            salesTax: null,
            netRevenue: null,
            totalProjectCost: $totalProjectCost,
            marginPercent: $marginPercent,
            members: [],
        );
    }

    /**
     * @param list<array{0: IndustryProject, 1: DistributionResult}> $projectsWithDistribution
     *
     * @return list<ProjectReference>
     */
    private function readWith(array $projectsWithDistribution): array
    {
        $distributions = new \SplObjectStorage();
        $memberships = [];
        foreach ($projectsWithDistribution as [$project, $distribution]) {
            $distributions[$project] = $distribution;
            $memberships[] = $this->membershipOn($project);
        }

        $repository = $this->createStub(IndustryProjectParticipantRepository::class);
        $repository->method('findAcceptedOnProjectsCompletedBetween')->willReturn($memberships);

        $distributionService = $this->createStub(ProjectDistributionService::class);
        $distributionService->method('calculateDistribution')->willReturnCallback(
            static fn (IndustryProject $project): DistributionResult => $distributions[$project],
        );

        return (new ProjectReferenceReader($repository, $distributionService))->read($this->reader, $this->now);
    }

    private function membershipOn(IndustryProject $project): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())
            ->setUser($this->reader)
            ->setRole(ParticipantRole::Owner)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);

        return $member;
    }
}
