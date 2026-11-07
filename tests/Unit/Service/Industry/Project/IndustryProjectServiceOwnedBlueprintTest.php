<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use App\Service\Industry\InventionService;
use App\Service\Industry\Project\CreateProjectData;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IndustryProjectService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-357')]
final class IndustryProjectServiceOwnedBlueprintTest extends TestCase
{
    private const int BLUEPRINT_TYPE_ID_OFFSET = 500_000;
    private const int BASE_TIME_SECONDS = 2_400;
    private const int MAX_PRODUCTION_LIMIT = 300;
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8357';

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int SABRE = 22456;
    private const int THRASHER = 16242;
    private const int SABRE_RUNS = 10;
    private const int THRASHER_RUNS = 4;

    private const array PRODUCT_NAMES = [
        self::SABRE => 'Sabre',
        self::THRASHER => 'Thrasher',
    ];

    private const array BASE_QUANTITIES_PER_RUN = [
        self::SABRE => [self::TRITANIUM => 5_000],
        self::THRASHER => [self::PYERITE => 3_000],
    ];

    public function testProductWhoseOriginalBlueprintIsOwnedHasNoCopyLineWhileTheOtherKeepsIt(): void
    {
        $owner = $this->createUser();
        $originalBlueprintFinder = $this->createMock(OriginalBlueprintFinder::class);
        $originalBlueprintFinder->expects(self::atLeastOnce())
            ->method('typeIdsOwnedBy')
            ->with($owner)
            ->willReturn([self::SABRE + self::BLUEPRINT_TYPE_ID_OFFSET]);

        $project = $this->createProjectOfSabreAndThrasher($owner, $originalBlueprintFinder);

        self::assertSame(
            [
                [self::THRASHER, 'copy', 'blueprint', self::THRASHER_RUNS, self::THRASHER_RUNS, null],
                [self::THRASHER, 'manufacturing', 'final', self::THRASHER_RUNS, self::THRASHER_RUNS, null],
                [self::SABRE, 'manufacturing', 'final', self::SABRE_RUNS, self::SABRE_RUNS, null],
            ],
            $this->jobLines($project),
        );
    }

    public function testProductWhoseBlueprintIsOnlyOwnedAsCopyKeepsItsCopyLine(): void
    {
        $owner = $this->createUser();
        $originalBlueprintFinder = $this->createStub(OriginalBlueprintFinder::class);
        $originalBlueprintFinder->method('typeIdsOwnedBy')->willReturn([]);

        $project = $this->createProjectOfSabreAndThrasher($owner, $originalBlueprintFinder);

        self::assertSame(
            [
                [self::THRASHER, 'copy', 'blueprint', self::THRASHER_RUNS, self::THRASHER_RUNS, null],
                [self::THRASHER, 'manufacturing', 'final', self::THRASHER_RUNS, self::THRASHER_RUNS, null],
                [self::SABRE, 'copy', 'blueprint', self::SABRE_RUNS, self::SABRE_RUNS, null],
                [self::SABRE, 'manufacturing', 'final', self::SABRE_RUNS, self::SABRE_RUNS, null],
            ],
            $this->jobLines($project),
        );
    }

    private function createProjectOfSabreAndThrasher(User $owner, OriginalBlueprintFinder $originalBlueprintFinder): IndustryProject
    {
        return $this->service($originalBlueprintFinder)->createProject($owner, new CreateProjectData(
            name: 'Owned blueprints',
            items: [
                ['typeId' => self::SABRE, 'typeName' => 'Sabre', 'meLevel' => 2, 'teLevel' => 4, 'runs' => self::SABRE_RUNS],
                ['typeId' => self::THRASHER, 'typeName' => 'Thrasher', 'meLevel' => 2, 'teLevel' => 4, 'runs' => self::THRASHER_RUNS],
            ],
        ));
    }

    private function service(OriginalBlueprintFinder $originalBlueprintFinder): IndustryProjectService
    {
        $recipes = [];
        foreach (self::BASE_QUANTITIES_PER_RUN as $productTypeId => $baseQuantities) {
            $materials = [];
            foreach ($baseQuantities as $materialTypeId => $baseQuantityPerRun) {
                $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
            }
            $recipes[] = new Recipe(
                $productTypeId,
                ActivityKind::Manufacturing,
                new OutputPerRun(1),
                $materials,
                new Runs(self::MAX_PRODUCTION_LIMIT),
                self::BASE_TIME_SECONDS,
            );
        }

        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        return new IndustryProjectService(
            new ProductionPlanning(
                new InMemoryBlueprintCatalog(...$recipes),
                InMemorySystemCostIndices::none(),
                new InMemoryRigCategories(),
                new StructureTotalCostComparator(new InMemoryMarketPrices([]), new InMemoryAdjustedPrices([]), InMemorySystemCostIndices::none(), new InMemoryJumpDistances(), new InMemoryItemVolumes()),
            ),
            new InMemoryUserProductionStructures([self::OWNER_ID => []]),
            $this->createMock(JitaMarketService::class),
            $this->entityManager(),
            $this->createStub(InventionService::class),
            $this->activityProductRepository(),
            $this->activityRepository(),
            $this->createStub(IndustryProjectContributionRepository::class),
            $settingsRepository,
            $originalBlueprintFinder,
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('findBy')->willReturnCallback(static fn (array $criteria): array => array_values(array_map(
            static fn (int $typeId): InvType => (new InvType())->setTypeId($typeId)->setTypeName(self::PRODUCT_NAMES[$typeId] ?? 'Material ' . $typeId),
            (array) $criteria['typeId'],
        )));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($this->createStub(Connection::class));
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );

        return $entityManager;
    }

    private function activityProductRepository(): IndustryActivityProductRepository
    {
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct => isset(self::BASE_QUANTITIES_PER_RUN[$productTypeId]) && $activityId === IndustryActivityType::Manufacturing->value
                ? (new IndustryActivityProduct())
                    ->setTypeId($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET)
                    ->setActivityId($activityId)
                    ->setProductTypeId($productTypeId)
                    ->setQuantity(1)
                : null,
        );
        $repository->method('findProductsForBlueprints')->willReturn([]);

        return $repository;
    }

    private function activityRepository(): IndustryActivityRepository
    {
        $repository = $this->createStub(IndustryActivityRepository::class);
        $repository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    if (!isset(self::BASE_QUANTITIES_PER_RUN[$blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET])) {
                        continue;
                    }
                    foreach ($activityIds as $activityId) {
                        $activities[$blueprintTypeId . '-' . $activityId] = (new IndustryActivity())
                            ->setTypeId($blueprintTypeId)
                            ->setActivityId($activityId)
                            ->setTime(self::BASE_TIME_SECONDS);
                    }
                }

                return $activities;
            },
        );

        return $repository;
    }

    private function createUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));

        return $user;
    }

    private function jobLines(IndustryProject $project): array
    {
        $jobLines = array_map(
            static fn (IndustryProjectBomLine $line): array => [
                $line->getTypeId(),
                $line->getActivityType(),
                $line->getJobGroup(),
                $line->getRuns(),
                $line->getRequiredQuantity(),
                $line->getParentTypeId(),
            ],
            array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $line): bool => $line->isJob())),
        );
        usort($jobLines, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        return $jobLines;
    }
}
