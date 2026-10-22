<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;

trait StubsGoldenSdeRepositories
{
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../Fixtures/Golden/sde-blueprint-recipes.json';

    private const int SYNTHETIC_BLUEPRINT_OFFSET = 1_000_000_000;
    private const int SYNTHETIC_MAX_PRODUCTION_LIMIT = 100;
    private const int SYNTHETIC_ACTIVITY_TIME = 600;

    private const int HAIL_L = 12779;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;

    private const int HAIL_L_T1_BLUEPRINT = 2_000_000_001;
    private const int HAIL_L_T1_PRODUCT = 2_000_000_002;
    private const int HAIL_L_T1_OUTPUT_PER_RUN = 5000;
    private const array HAIL_L_T1_MATERIALS = [self::TRITANIUM => 1000, self::PYERITE => 500];
    private const int STUB_DATACORE_A = 2_000_000_011;
    private const int STUB_DATACORE_B = 2_000_000_012;
    private const int DATACORE_QUANTITY_PER_ATTEMPT = 2;
    private const float HAIL_L_INVENTION_PROBABILITY = 0.34;
    private const int HAIL_L_INVENTED_RUNS = 10;

    private array $recipes = [];
    private array $typeNames = [];
    private array $typesMissingFromSde = [];
    private ?float $hailLInventionProbability = self::HAIL_L_INVENTION_PROBABILITY;

    private function loadGoldenSde(): void
    {
        $fixture = json_decode((string) file_get_contents(self::SDE_RECIPES_FIXTURE), true, flags: \JSON_THROW_ON_ERROR);
        $this->recipes = $fixture['recipes'];
        $this->typeNames = $fixture['typeNames'] + [
            (string) self::HAIL_L_T1_PRODUCT => 'Stub T1 ammunition',
            (string) self::STUB_DATACORE_A => 'Stub Datacore A',
            (string) self::STUB_DATACORE_B => 'Stub Datacore B',
        ];
    }

    private function sdeProductRepository(): IndustryActivityProductRepository
    {
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId): ?IndustryActivityProduct {
                if (IndustryActivityType::Invention->value === $activityId) {
                    return $productTypeId === $this->blueprintOf(self::HAIL_L)
                        ? $this->product(self::HAIL_L_T1_BLUEPRINT, $activityId, $productTypeId, self::HAIL_L_INVENTED_RUNS, $this->hailLInventionProbability)
                        : null;
                }
                if (self::HAIL_L_T1_PRODUCT === $productTypeId) {
                    return IndustryActivityType::Manufacturing->value === $activityId ? $this->hailLT1Manufacturing() : null;
                }
                $recipe = $this->recipes[(string) $productTypeId] ?? null;
                if (null === $recipe || $this->activityOf($recipe) !== $activityId) {
                    return null;
                }

                return $this->product($this->blueprintOf($productTypeId), $activityId, $productTypeId, $recipe['outputPerRun']);
            },
        );
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryActivityProduct => self::HAIL_L_T1_BLUEPRINT === $criteria['typeId'] && IndustryActivityType::Manufacturing->value === $criteria['activityId']
                ? $this->hailLT1Manufacturing()
                : null,
        );
        $repository->method('findBy')->willReturnCallback(
            function (array $criteria): array {
                $blueprintTypeId = (int) $criteria['typeId'];
                if (self::HAIL_L_T1_BLUEPRINT === $blueprintTypeId) {
                    return [$this->hailLT1Manufacturing()];
                }
                $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_OFFSET;
                $recipe = $this->recipes[(string) $productTypeId] ?? null;
                if (null === $recipe || $this->activityOf($recipe) !== $criteria['activityId']) {
                    return [];
                }

                return [$this->product($blueprintTypeId, $criteria['activityId'], $productTypeId, $recipe['outputPerRun'])];
            },
        );

        return $repository;
    }

    private function sdeMaterialRepository(): IndustryActivityMaterialRepository
    {
        $repository = $this->createStub(IndustryActivityMaterialRepository::class);
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_map(
                fn (array $row): IndustryActivityMaterial => $this->material((int) $criteria['typeId'], (int) $criteria['activityId'], $row['materialTypeId'], $row['quantity']),
                $this->baseMaterialsOf((int) $criteria['typeId'], (int) $criteria['activityId']),
            ),
        );
        $repository->method('findMaterialsForBlueprints')->willReturnCallback(
            fn (array $blueprintTypeIds, array $activityIds): array => [
                $blueprintTypeIds[0] => $this->baseMaterialsOf($blueprintTypeIds[0], $activityIds[0]),
            ],
        );
        $repository->method('findByBlueprintAndActivity')->willReturnCallback(
            function (int $blueprintTypeId, int $activityId): array {
                if (self::HAIL_L_T1_BLUEPRINT === $blueprintTypeId) {
                    $materials = match ($activityId) {
                        IndustryActivityType::Invention->value => [self::STUB_DATACORE_A => self::DATACORE_QUANTITY_PER_ATTEMPT, self::STUB_DATACORE_B => self::DATACORE_QUANTITY_PER_ATTEMPT],
                        IndustryActivityType::Manufacturing->value => self::HAIL_L_T1_MATERIALS,
                        default => [],
                    };

                    return array_map(
                        fn (int $materialTypeId, int $quantity): IndustryActivityMaterial => $this->material($blueprintTypeId, $activityId, $materialTypeId, $quantity),
                        array_keys($materials),
                        $materials,
                    );
                }

                return array_map(
                    fn (array $row): IndustryActivityMaterial => $this->material($blueprintTypeId, $activityId, $row['materialTypeId'], $row['quantity']),
                    $this->baseMaterialsOf($blueprintTypeId, $activityId),
                );
            },
        );

        return $repository;
    }

    private function sdeInvTypeRepository(): InvTypeRepository
    {
        $knownTypes = [];
        foreach ($this->typeNames as $typeId => $typeName) {
            if (!\in_array((int) $typeId, $this->typesMissingFromSde, true)) {
                $knownTypes[(int) $typeId] = (new InvType())->setTypeId((int) $typeId)->setTypeName($typeName);
            }
        }

        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => \is_int($typeId) ? ($knownTypes[$typeId] ?? null) : null,
        );
        $repository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($knownTypes, array_flip($typeIds)),
        );

        return $repository;
    }

    private function sdeBlueprintRepository(): IndustryBlueprintRepository
    {
        $repository = $this->createStub(IndustryBlueprintRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (int $blueprintTypeId): IndustryBlueprint => (new IndustryBlueprint())
                ->setTypeId($blueprintTypeId)
                ->setMaxProductionLimit(self::SYNTHETIC_MAX_PRODUCTION_LIMIT),
        );

        return $repository;
    }

    private function sdeActivityTimeRepository(): IndustryActivityRepository
    {
        $repository = $this->createStub(IndustryActivityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): IndustryActivity => (new IndustryActivity())
                ->setTypeId((int) $criteria['typeId'])
                ->setActivityId((int) $criteria['activityId'])
                ->setTime(self::SYNTHETIC_ACTIVITY_TIME),
        );

        return $repository;
    }

    private function hailLT1Manufacturing(): IndustryActivityProduct
    {
        return $this->product(self::HAIL_L_T1_BLUEPRINT, IndustryActivityType::Manufacturing->value, self::HAIL_L_T1_PRODUCT, self::HAIL_L_T1_OUTPUT_PER_RUN);
    }

    private function baseMaterialsOf(int $blueprintTypeId, int $activityId): array
    {
        $recipe = $this->recipes[(string) ($blueprintTypeId - self::SYNTHETIC_BLUEPRINT_OFFSET)] ?? null;
        if (null === $recipe || $this->activityOf($recipe) !== $activityId) {
            return [];
        }
        $materials = [];
        foreach ($recipe['materials'] as $materialTypeId => $quantity) {
            $materials[] = ['materialTypeId' => (int) $materialTypeId, 'quantity' => $quantity];
        }

        return $materials;
    }

    private function activityOf(array $recipe): int
    {
        return 'reaction' === $recipe['activity'] ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }

    private function blueprintOf(int $productTypeId): int
    {
        return $productTypeId + self::SYNTHETIC_BLUEPRINT_OFFSET;
    }

    private function product(int $blueprintTypeId, int $activityId, int $productTypeId, int $quantity, ?float $probability = null): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($quantity)
            ->setProbability($probability);
    }

    private function material(int $blueprintTypeId, int $activityId, int $materialTypeId, int $quantity): IndustryActivityMaterial
    {
        return (new IndustryActivityMaterial())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setMaterialTypeId($materialTypeId)
            ->setQuantity($quantity);
    }
}
