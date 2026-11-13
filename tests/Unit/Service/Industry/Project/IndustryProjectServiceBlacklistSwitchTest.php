<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectPurchase;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Enum\BomLineMarkState;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use App\Tests\Unit\Service\Industry\ProducerOfStub;

#[CoversClass(IndustryProjectService::class)]
#[Group('issue-343')]
final class IndustryProjectServiceBlacklistSwitchTest extends TestCase
{
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8058';
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int RIFTER = 587;
    private const int HULL_PLATING = 1001;
    private const int BASE_TIME_SECONDS = 2_400;
    private const int MAX_PRODUCTION_LIMIT = 300;
    private const array TYPE_NAMES = [
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::RIFTER => 'Rifter',
        self::HULL_PLATING => 'Hull Plating',
    ];

    private array $contributedLineIds = [];

    public function testBuildBomRefusesToDropAJobLineCarryingALinkedJobAndNamesItsType(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->linkJobTo($this->lineOf($project, self::HULL_PLATING, true));
        $project->setBlacklistTypeIds([self::HULL_PLATING]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The line "Hull Plating" carries a linked job, a purchase or a contribution and cannot be removed from the plan.');

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);
    }

    public function testBuildBomRefusesToDropAMaterialLineCarryingAPurchaseAndNamesItsType(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->purchaseOn($this->lineOf($project, self::PYERITE, false));
        $project->setBlacklistTypeIds([self::HULL_PLATING]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The line "Pyerite" carries a linked job, a purchase or a contribution and cannot be removed from the plan.');

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);
    }

    public function testBuildBomRefusesToDropALineCarryingAContributionAndNamesItsType(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->contributedLineIds = [$this->lineOf($project, self::PYERITE, false)->getId()->toRfc4122()];
        $project->setBlacklistTypeIds([self::HULL_PLATING]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The line "Pyerite" carries a linked job, a purchase or a contribution and cannot be removed from the plan.');

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);
    }

    public function testBuildBomLeavesEveryLineUntouchedWhenItRefusesToDropAWorkedLine(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->linkJobTo($this->lineOf($project, self::HULL_PLATING, true));
        $project->setBlacklistTypeIds([self::HULL_PLATING]);
        $linesBefore = $this->linesOf($project);

        try {
            $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);
            $this->fail('The rebuild should have been refused.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($linesBefore, $this->linesOf($project));
    }

    public function testBuildBomDropsTheLinesWithoutWorkWhenItIsAskedToRefuseWorkedLines(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $project->setBlacklistTypeIds([self::HULL_PLATING]);

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);

        $this->assertSame(
            [[self::TRITANIUM, false, 100_000], [self::RIFTER, true, 5], [self::HULL_PLATING, false, 15]],
            $this->linesOf($project),
        );
    }

    public function testBuildBomKeepsTheContributedLineAndDropsTheOthersWhenItIsNotAskedToRefuseWorkedLines(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->contributedLineIds = [$this->lineOf($project, self::HULL_PLATING, true)->getId()->toRfc4122()];
        $project->setBlacklistTypeIds([self::HULL_PLATING]);

        $this->service()->buildBom($project, $this->owner());

        $this->assertSame(
            [[self::TRITANIUM, false, 100_000], [self::RIFTER, true, 5], [self::HULL_PLATING, false, 15], [self::HULL_PLATING, true, 15]],
            $this->linesOf($project),
        );
    }

    #[Group('issue-807')]
    public function testBuildBomRefusesToDropAJobLineCarryingAStepMarkAndNamesItsType(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->markStep($this->lineOf($project, self::HULL_PLATING, true), BomLineMarkState::Done, 15);
        $project->setBlacklistTypeIds([self::HULL_PLATING]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The line "Hull Plating" carries a linked job, a purchase or a contribution and cannot be removed from the plan.');

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);
    }

    #[Group('issue-807')]
    public function testBuildBomRefusesToCutAJobLineBelowItsMarkedRunsAndNamesItsType(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $this->markStep($this->lineOf($project, self::RIFTER, true), BomLineMarkState::Done, 4);
        $project->getItems()->first()->setRuns(3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The line "Rifter" has 4 runs marked, more than the 3 the plan now needs.');

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);
    }

    #[Group('issue-807')]
    public function testBuildBomKeepsTheStepMarkOfAJobLineWhoseRunsFallToTheMarkedRuns(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $rifterLine = $this->lineOf($project, self::RIFTER, true);
        $stepMark = $this->markStep($rifterLine, BomLineMarkState::Launched, 3);
        $project->getItems()->first()->setRuns(3);

        $this->service()->buildBom($project, $this->owner(), refuseToDropWorkedLines: true);

        self::assertSame([3, $stepMark], [$rifterLine->getRuns(), $rifterLine->getMark()]);
    }

    #[Group('issue-807')]
    public function testBuildBomKeepsTheStepMarkOfAJobLineCutBelowItsMarkedRunsWhenItIsNotAskedToRefuseWorkedLines(): void
    {
        $project = $this->projectBuiltWithoutBlacklist();
        $rifterLine = $this->lineOf($project, self::RIFTER, true);
        $stepMark = $this->markStep($rifterLine, BomLineMarkState::Done, 4);
        $project->getItems()->first()->setRuns(3);

        $this->service()->buildBom($project, $this->owner());

        self::assertSame([3, $stepMark, 3], [$rifterLine->getRuns(), $rifterLine->getMark(), $rifterLine->getCountedRuns()]);
    }

    private function projectBuiltWithoutBlacklist(): IndustryProject
    {
        $project = new IndustryProject();
        $project->addItem((new IndustryProjectProduct())
            ->setTypeId(self::RIFTER)
            ->setTypeName('Rifter')
            ->setRuns(5)
            ->setMeLevel(0)
            ->setTeLevel(0)
            ->setSortOrder(0));

        $this->service()->buildBom($project, $this->owner());

        return $project;
    }

    private function lineOf(IndustryProject $project, int $typeId, bool $isJob): IndustryProjectBomLine
    {
        foreach ($project->getBomItems() as $line) {
            if ($line->getTypeId() === $typeId && $line->isJob() === $isJob) {
                return $line;
            }
        }

        $this->fail(sprintf('No line for type %d.', $typeId));
    }

    private function linkJobTo(IndustryProjectBomLine $line): void
    {
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(900_001)->setRuns(3));
    }

    private function markStep(IndustryProjectBomLine $line, BomLineMarkState $state, int $runs): IndustryProjectBomLineMark
    {
        $stepMark = new IndustryProjectBomLineMark($state, $runs, new \DateTimeImmutable('2026-11-13T09:00:00+00:00'));
        $line->setMark($stepMark);

        return $stepMark;
    }

    private function purchaseOn(IndustryProjectBomLine $line): void
    {
        $line->addPurchase((new IndustryProjectPurchase())
            ->setTypeId($line->getTypeId())
            ->setQuantity(40)
            ->setUnitPrice(6.5)
            ->setTotalPrice(260.0)
            ->setSource('manual'));
    }

    private function linesOf(IndustryProject $project): array
    {
        $lines = array_map(
            static fn (IndustryProjectBomLine $line): array => [$line->getTypeId(), $line->isJob(), $line->getRequiredQuantity()],
            array_values($project->getBomItems()->toArray()),
        );
        usort($lines, static fn (array $left, array $right): int => $left <=> $right);

        return $lines;
    }

    private function owner(): User
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));

        return $owner;
    }

    private function service(): IndustryProjectService
    {
        $recipes = [
            new Recipe(self::RIFTER, ActivityKind::Manufacturing, new OutputPerRun(1), [
                new RecipeMaterial(self::TRITANIUM, new Quantity(20_000)),
                new RecipeMaterial(self::HULL_PLATING, new Quantity(3)),
            ], new Runs(self::MAX_PRODUCTION_LIMIT), self::BASE_TIME_SECONDS),
            new Recipe(self::HULL_PLATING, ActivityKind::Manufacturing, new OutputPerRun(1), [
                new RecipeMaterial(self::PYERITE, new Quantity(40)),
            ], new Runs(self::MAX_PRODUCTION_LIMIT), self::BASE_TIME_SECONDS),
        ];

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('findBy')->willReturnCallback(static fn (array $criteria): array => array_values(array_map(
            static fn (int $typeId): InvType => (new InvType())->setTypeId($typeId)->setTypeName(self::TYPE_NAMES[$typeId]),
            array_values(array_filter((array) $criteria['typeId'], static fn (int $typeId): bool => isset(self::TYPE_NAMES[$typeId]))),
        )));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );

        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);

        ProducerOfStub::from($activityProductRepository);
        $activityProductRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct => $activityId === IndustryActivityType::Manufacturing->value && in_array($productTypeId, [self::RIFTER, self::HULL_PLATING], true)
                ? (new IndustryActivityProduct())->setTypeId($productTypeId + 500_000)->setActivityId($activityId)->setProductTypeId($productTypeId)->setQuantity(1)
                : null,
        );

        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findContributedBomItemIds')->willReturnCallback(fn (): array => $this->contributedLineIds);

        return new IndustryProjectService(
            new ProductionPlanning(
                new InMemoryBlueprintCatalog(...$recipes),
                InMemorySystemCostIndices::none(),
                new InMemoryRigCategories(),
                new StructureTotalCostComparator(new InMemoryMarketPrices([]), new InMemoryAdjustedPrices([]), InMemorySystemCostIndices::none(), new InMemoryJumpDistances(), new InMemoryItemVolumes()),
            ),
            new InMemoryUserProductionStructures([self::OWNER_ID => []]),
            $this->createStub(JitaMarketService::class),
            $entityManager,
            $this->createStub(InventionService::class),
            $activityProductRepository,
            $this->createStub(IndustryActivityRepository::class),
            $contributionRepository,
            $this->createStub(IndustryUserSettingsRepository::class),
            $this->createStub(OriginalBlueprintFinder::class),
        );
    }
}
