<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\IndustryActivityType;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use App\Service\Industry\InventionService;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Uid\Uuid;
use App\Tests\Unit\Service\Industry\ProducerOfStub;

trait BuildsProjectServiceOnGoldenSde
{
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../../Fixtures/Golden/sde-blueprint-recipes.json';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8057';

    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int NITROGEN_FUEL_BLOCK = 4051;

    private array $sde;
    private array $jitaPrices = [];
    private array $jitaLookups = [];
    private array $contributions = [];
    private array $committedState = [];
    private ?IndustryProject $committedProject = null;
    private bool $inTransaction = false;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProjectService $projectService;
    private JitaMarketService $jitaMarket;
    private IndustryProjectContributionRepository $contributionRepository;
    private User $owner;

    private function setUpGoldenSde(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::SDE_RECIPES_FIXTURE), true, flags: \JSON_THROW_ON_ERROR);

        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->createStub(Connection::class));
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->contributions[] = $entity;
            }
        });
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            if (!$this->inTransaction) {
                $this->commit();
            }
        });
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $work): mixed {
            $this->inTransaction = true;
            try {
                $result = $work($this->entityManager);
                $this->commit();

                return $result;
            } finally {
                $this->inTransaction = false;
            }
        });

        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getCheapestPercentilePrices')->willReturnCallback(function (array $typeIds, float $percentile = 0.05): array {
            $this->jitaLookups[] = [$typeIds, $percentile];
            $prices = [];
            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->jitaPrices[$typeId] ?? null;
            }

            return $prices;
        });

        $this->jitaMarket = $jitaMarket;
        $this->owner = $this->createStub(User::class);
        $this->owner->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));

        $this->contributionRepository = $this->contributionRepositoryOnTheRecordedContributions();

        $productRepository = $this->productRepositoryOnGoldenSde();
        $activityRepository = $this->activityRepositoryOnGoldenSde();
        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('getInventionData')->willReturn(null);

        $this->projectService = new IndustryProjectService(
            new ProductionPlanning(
                new SdeBlueprintCatalog($productRepository, $activityRepository, $this->materialRepositoryOnGoldenSde(), $this->blueprintRepositoryOnGoldenSde()),
                InMemorySystemCostIndices::none(),
                new InMemoryRigCategories(),
            ),
            new InMemoryUserProductionStructures([self::OWNER_ID => []]),
            $jitaMarket,
            $this->entityManager,
            $inventionService,
            $productRepository,
            $activityRepository,
            $this->contributionRepository,
            $this->createStub(IndustryUserSettingsRepository::class),
            $this->createStub(OriginalBlueprintFinder::class),
        );
    }

    private function soloProjectOf(array $items): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner);
        $project->enrolOwner();
        foreach ($items as $index => [$typeId, $runs, $me, $te]) {
            $project->addItem((new IndustryProjectProduct())
                ->setTypeId($typeId)
                ->setTypeName($this->sde['typeNames'][(string) $typeId])
                ->setRuns($runs)
                ->setMeLevel($me)
                ->setTeLevel($te)
                ->setSortOrder($index));
        }

        return $project;
    }

    private function ownerOf(IndustryProject $project): IndustryProjectParticipant
    {
        foreach ($project->getMembers() as $member) {
            if ($member->getUser() === $this->owner) {
                return $member;
            }
        }

        self::fail('The project has no participant for its owner.');
    }

    private function jobLinesOf(IndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $line): bool => $line->isJob()));
    }

    private function materialLinesOf(IndustryProject $project): array
    {
        return array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $line): bool => !$line->isJob()));
    }

    private function lineOf(IndustryProject $project, int $typeId, string $activityType, string $jobGroup): IndustryProjectBomLine
    {
        foreach ($this->jobLinesOf($project) as $line) {
            if ($line->getTypeId() === $typeId && $line->getActivityType() === $activityType && $line->getJobGroup() === $jobGroup) {
                return $line;
            }
        }

        self::fail(\sprintf('The BOM has no %s %s line of type %d.', $jobGroup, $activityType, $typeId));
    }

    private function materialLineOf(IndustryProject $project, int $typeId): IndustryProjectBomLine
    {
        foreach ($this->materialLinesOf($project) as $line) {
            if ($line->getTypeId() === $typeId) {
                return $line;
            }
        }

        self::fail(\sprintf('The BOM has no material line of type %d.', $typeId));
    }

    private function jobs(IndustryProject $project): array
    {
        $jobs = array_map(static fn (IndustryProjectBomLine $line): array => [
            $line->getTypeId(),
            (string) $line->getActivityType(),
            (string) $line->getJobGroup(),
            $line->getRuns(),
            $line->getMeLevel(),
            $line->getTeLevel(),
            $line->getRequiredQuantity(),
        ], $this->jobLinesOf($project));
        usort($jobs, static fn (array $left, array $right): int => $left <=> $right);

        return $jobs;
    }

    private function materials(IndustryProject $project): array
    {
        $materials = [];
        foreach ($this->materialLinesOf($project) as $line) {
            $materials[$line->getTypeId()] = $line->getRequiredQuantity();
        }
        ksort($materials);

        return $materials;
    }

    private function approvedMaterialContribution(IndustryProject $project, IndustryProjectBomLine $line, int $quantity): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setMember($this->ownerOf($project))
            ->setBomItem($line)
            ->setType(ContributionType::Material)
            ->setQuantity($quantity)
            ->setEstimatedValue(0.0)
            ->setStatus(ContributionStatus::Approved);
        $project->addContribution($contribution);
        $this->contributions[] = $contribution;
        $line->setFulfilledQuantity($line->getFulfilledQuantity() + $quantity);

        return $contribution;
    }

    private function snapshotAsCommitted(IndustryProject $project): void
    {
        $this->committedProject = $project;
        $this->commit();
    }

    private function commit(): void
    {
        $project = $this->committedProject;
        if ($project === null) {
            return;
        }

        $this->committedState = [
            'approvedContributions' => array_values(array_map(
                static fn (IndustryProjectContribution $contribution): string => $contribution->getId()->toRfc4122(),
                array_filter($this->contributions, static fn (IndustryProjectContribution $contribution): bool => $contribution->getStatus() === ContributionStatus::Approved),
            )),
            'lines' => array_map(static fn (IndustryProjectBomLine $line): array => [
                $line->getId()->toRfc4122(),
                $line->getTypeId(),
                $line->getRuns(),
                $line->getRequiredQuantity(),
                $line->getFulfilledQuantity(),
            ], $project->getBomItems()->toArray()),
        ];
    }

    private function contributionRepositoryOnTheRecordedContributions(): IndustryProjectContributionRepository
    {
        $repository = $this->createStub(IndustryProjectContributionRepository::class);
        $repository->method('findContributedBomItemIds')->willReturnCallback(function (IndustryProject $project): array {
            $lineIds = [];
            foreach ($this->contributions as $contribution) {
                $line = $contribution->getBomItem();
                if ($line !== null && $contribution->getProject() === $project) {
                    $lineIds[$line->getId()->toRfc4122()] = true;
                }
            }

            return array_keys($lineIds);
        });
        $repository->method('findIntermediateStockByTypeId')->willReturnCallback(function (IndustryProject $project): array {
            $stockByTypeId = [];
            foreach ($this->contributions as $contribution) {
                $line = $contribution->getBomItem();
                if ($contribution->getProject() !== $project
                    || $contribution->getStatus() !== ContributionStatus::Approved
                    || $contribution->getType() !== ContributionType::Material
                    || $line === null
                    || !$line->isJob()) {
                    continue;
                }
                $stockByTypeId[$line->getTypeId()] = ($stockByTypeId[$line->getTypeId()] ?? 0) + $contribution->getQuantity();
            }

            return $stockByTypeId;
        });
        $repository->method('findByMemberBomItemAndType')->willReturnCallback(function (IndustryProjectParticipant $member, IndustryProjectBomLine $line, ContributionType $type, array $statuses): ?IndustryProjectContribution {
            foreach ($this->contributions as $contribution) {
                if ($contribution->getMember() === $member
                    && $contribution->getBomItem() === $line
                    && $contribution->getType() === $type
                    && \in_array($contribution->getStatus(), $statuses, true)) {
                    return $contribution;
                }
            }

            return null;
        });

        return $repository;
    }

    private function productRepositoryOnGoldenSde(): IndustryActivityProductRepository
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        ProducerOfStub::from($productRepository);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct {
                $recipe = $this->sde['recipes'][(string) $productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return self::activityProduct($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET, $activityId, $productTypeId, $recipe['outputPerRun']);
            },
        );
        $productRepository->method('findProductsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $products = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    $productTypeId = $blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET;
                    $recipe = $this->sde['recipes'][(string) $productTypeId] ?? null;
                    if ($recipe !== null && \in_array(self::activityIdOf($recipe['activity']), $activityIds, true)) {
                        $activityId = self::activityIdOf($recipe['activity']);
                        $products[$blueprintTypeId.'-'.$activityId] = self::activityProduct($blueprintTypeId, $activityId, $productTypeId, $recipe['outputPerRun']);
                    }
                }

                return $products;
            },
        );

        return $productRepository;
    }

    private function materialRepositoryOnGoldenSde(): IndustryActivityMaterialRepository
    {
        $materialsOf = function (int $blueprintTypeId, int $activityId): array {
            $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
            if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                return [];
            }
            $materials = [];
            foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                $materials[] = (new IndustryActivityMaterial())
                    ->setTypeId($blueprintTypeId)
                    ->setActivityId($activityId)
                    ->setMaterialTypeId((int) $materialTypeId)
                    ->setQuantity($baseQuantityPerRun);
            }

            return $materials;
        };

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findByBlueprintAndActivity')->willReturnCallback(
            static fn (int $blueprintTypeId, int $activityId = 1): array => $materialsOf($blueprintTypeId, $activityId),
        );
        $materialRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => $materialsOf((int) $criteria['typeId'], (int) $criteria['activityId']),
        );

        return $materialRepository;
    }

    private function blueprintRepositoryOnGoldenSde(): IndustryBlueprintRepository
    {
        $blueprintOf = function (int $blueprintTypeId): ?IndustryBlueprint {
            $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
            if ($recipe === null) {
                return null;
            }

            return (new IndustryBlueprint())
                ->setTypeId($blueprintTypeId)
                ->setMaxProductionLimit($recipe['maxProductionLimit']);
        };

        $blueprintRepository = $this->createStub(IndustryBlueprintRepository::class);
        $blueprintRepository->method('find')->willReturnCallback(
            static fn (mixed $blueprintTypeId): ?IndustryBlueprint => \is_int($blueprintTypeId) ? $blueprintOf($blueprintTypeId) : null,
        );
        $blueprintRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($blueprintOf, (array) ($criteria['typeId'] ?? [])))),
        );

        return $blueprintRepository;
    }

    private function activityRepositoryOnGoldenSde(): IndustryActivityRepository
    {
        $activityOf = function (int $blueprintTypeId, int $activityId): ?IndustryActivity {
            $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
            if ($recipe === null) {
                return null;
            }
            $hasActivity = $activityId === IndustryActivityType::Copying->value
                ? $recipe['hasCopyActivity']
                : self::activityIdOf($recipe['activity']) === $activityId;

            return $hasActivity
                ? (new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($activityId)->setTime($recipe['baseTimeSeconds'])
                : null;
        };

        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivity => $activityOf((int) $criteria['typeId'], (int) $criteria['activityId']),
        );
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($activityOf): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($activityIds as $activityId) {
                        $activity = $activityOf($blueprintTypeId, $activityId);
                        if ($activity !== null) {
                            $activities[$blueprintTypeId.'-'.$activityId] = $activity;
                        }
                    }
                }

                return $activities;
            },
        );

        return $activityRepository;
    }

    private function typeRepositoryOnGoldenSde(): InvTypeRepository
    {
        $typeOf = fn (int $typeId): ?InvType => isset($this->sde['typeNames'][(string) $typeId])
            ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
            : null;

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback($typeOf);
        $typeRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($typeOf, (array) $criteria['typeId']))),
        );

        return $typeRepository;
    }

    private static function activityProduct(int $blueprintTypeId, int $activityId, int $productTypeId, int $outputPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($outputPerRun);
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
