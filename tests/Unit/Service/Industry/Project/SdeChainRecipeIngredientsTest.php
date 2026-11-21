<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\Project\SdeChainRecipeIngredients;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeChainRecipeIngredients::class)]
#[Group('issue-1153')]
final class SdeChainRecipeIngredientsTest extends TestCase
{
    private const int MANUFACTURING = 1;
    private const int REACTION = 11;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    private const int CRYSTALLINE_CARBONIDE = 16_670;
    private const int CRYSTALLITE_ALLOY = 16_672;
    private const int CARBON_POLYMERS = 16_659;
    private const int PHOTONIC_METAMATERIALS = 33_361;
    private const int FERNITE_CARBIDE = 16_673;

    private const int CARBONIDE_BLUEPRINT = 9_001;
    private const int ALLOY_BLUEPRINT = 9_002;
    private const int POLYMERS_BLUEPRINT = 9_003;
    private const int PHOTONIC_BLUEPRINT = 9_004;
    private const int FERNITE_BLUEPRINT = 9_005;

    private array $producerCalls = [];
    private array $materialCalls = [];

    public function testClosureFollowsTheRecipesOverSeveralLevelsAndKeepsOnlyProducibleIngredients(): void
    {
        $ingredients = $this->ingredientsOf(
            [
                self::CRYSTALLINE_CARBONIDE => ['blueprintTypeId' => self::CARBONIDE_BLUEPRINT, 'activityId' => self::MANUFACTURING],
                self::CRYSTALLITE_ALLOY => ['blueprintTypeId' => self::ALLOY_BLUEPRINT, 'activityId' => self::MANUFACTURING],
                self::CARBON_POLYMERS => ['blueprintTypeId' => self::POLYMERS_BLUEPRINT, 'activityId' => self::REACTION],
            ],
            [
                self::CARBONIDE_BLUEPRINT => [
                    ['materialTypeId' => self::CRYSTALLITE_ALLOY, 'quantity' => 40],
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 500],
                ],
                self::ALLOY_BLUEPRINT => [
                    ['materialTypeId' => self::CARBON_POLYMERS, 'quantity' => 12],
                    ['materialTypeId' => self::PYERITE, 'quantity' => 80],
                ],
                self::POLYMERS_BLUEPRINT => [
                    ['materialTypeId' => self::MEXALLON, 'quantity' => 25],
                ],
            ],
        );

        $result = $ingredients->producedIngredientsOf([self::CRYSTALLINE_CARBONIDE]);

        self::assertSame(
            $this->sortedByKey([
                self::CRYSTALLINE_CARBONIDE => [self::CRYSTALLITE_ALLOY],
                self::CRYSTALLITE_ALLOY => [self::CARBON_POLYMERS],
                self::CARBON_POLYMERS => [],
            ]),
            $this->sortedByKey($result),
        );
    }

    public function testRequestedRawMaterialIsAKeyWithAnEmptyList(): void
    {
        $result = $this->ingredientsOf([], [])->producedIngredientsOf([self::TRITANIUM]);

        self::assertSame([self::TRITANIUM => []], $result);
    }

    public function testEveryRequestedTypeIsAKeyEvenWhenOnlySomeHaveAProducer(): void
    {
        $ingredients = $this->ingredientsOf(
            [self::FERNITE_CARBIDE => ['blueprintTypeId' => self::FERNITE_BLUEPRINT, 'activityId' => self::MANUFACTURING]],
            [self::FERNITE_BLUEPRINT => [['materialTypeId' => self::ISOGEN, 'quantity' => 7]]],
        );

        $result = $ingredients->producedIngredientsOf([self::FERNITE_CARBIDE, self::TRITANIUM]);

        self::assertSame([self::TRITANIUM => [], self::FERNITE_CARBIDE => []], $this->sortedByKey($result));
    }

    public function testOneProducerQueryAndOneMaterialsQueryRunPerDepthNotPerType(): void
    {
        $ingredients = $this->ingredientsOf(
            [
                self::CRYSTALLINE_CARBONIDE => ['blueprintTypeId' => self::CARBONIDE_BLUEPRINT, 'activityId' => self::MANUFACTURING],
                self::PHOTONIC_METAMATERIALS => ['blueprintTypeId' => self::PHOTONIC_BLUEPRINT, 'activityId' => self::MANUFACTURING],
            ],
            [
                self::CARBONIDE_BLUEPRINT => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 500],
                    ['materialTypeId' => self::PYERITE, 'quantity' => 80],
                ],
                self::PHOTONIC_BLUEPRINT => [
                    ['materialTypeId' => self::PYERITE, 'quantity' => 30],
                    ['materialTypeId' => self::MEXALLON, 'quantity' => 25],
                ],
            ],
        );

        $result = $ingredients->producedIngredientsOf([self::CRYSTALLINE_CARBONIDE, self::PHOTONIC_METAMATERIALS]);

        self::assertSame([self::CRYSTALLINE_CARBONIDE => [], self::PHOTONIC_METAMATERIALS => []], $this->sortedByKey($result));
        self::assertSame(
            [[self::CRYSTALLINE_CARBONIDE, self::PHOTONIC_METAMATERIALS], [self::TRITANIUM, self::PYERITE, self::MEXALLON]],
            $this->producerCalls,
        );
        self::assertSame([[self::CARBONIDE_BLUEPRINT, self::PHOTONIC_BLUEPRINT]], $this->nonEmptyMaterialCalls());
    }

    public function testMaterialsAreAskedForTheManufacturingAndReactionActivities(): void
    {
        $activityIds = [];
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findProducersOf')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key(
                [self::CRYSTALLITE_ALLOY => ['blueprintTypeId' => self::ALLOY_BLUEPRINT, 'activityId' => self::MANUFACTURING]],
                array_flip($typeIds),
            ),
        );
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $requestedActivityIds) use (&$activityIds): array {
                $activityIds[] = $requestedActivityIds;

                return [];
            },
        );

        (new SdeChainRecipeIngredients($productRepository, $materialRepository))->producedIngredientsOf([self::CRYSTALLITE_ALLOY]);

        self::assertSame([[self::MANUFACTURING, self::REACTION]], $activityIds);
    }

    public function testIngredientReachedTwiceIsQueriedOnlyOnce(): void
    {
        $ingredients = $this->ingredientsOf(
            [
                self::CRYSTALLINE_CARBONIDE => ['blueprintTypeId' => self::CARBONIDE_BLUEPRINT, 'activityId' => self::MANUFACTURING],
                self::CRYSTALLITE_ALLOY => ['blueprintTypeId' => self::ALLOY_BLUEPRINT, 'activityId' => self::MANUFACTURING],
                self::CARBON_POLYMERS => ['blueprintTypeId' => self::POLYMERS_BLUEPRINT, 'activityId' => self::REACTION],
            ],
            [
                self::CARBONIDE_BLUEPRINT => [
                    ['materialTypeId' => self::CRYSTALLITE_ALLOY, 'quantity' => 40],
                    ['materialTypeId' => self::CARBON_POLYMERS, 'quantity' => 15],
                ],
                self::ALLOY_BLUEPRINT => [
                    ['materialTypeId' => self::CARBON_POLYMERS, 'quantity' => 12],
                ],
                self::POLYMERS_BLUEPRINT => [
                    ['materialTypeId' => self::MEXALLON, 'quantity' => 25],
                ],
            ],
        );

        $result = $ingredients->producedIngredientsOf([self::CRYSTALLINE_CARBONIDE]);

        self::assertSame(
            $this->sortedByKey([
                self::CRYSTALLINE_CARBONIDE => [self::CRYSTALLITE_ALLOY, self::CARBON_POLYMERS],
                self::CRYSTALLITE_ALLOY => [self::CARBON_POLYMERS],
                self::CARBON_POLYMERS => [],
            ]),
            $this->sortedByKey($result),
        );
        self::assertSame(
            [[self::CRYSTALLINE_CARBONIDE], [self::CARBON_POLYMERS, self::CRYSTALLITE_ALLOY], [self::MEXALLON]],
            $this->producerCalls,
        );
    }

    public function testRecipeCycleStopsInsteadOfLooping(): void
    {
        $ingredients = $this->ingredientsOf(
            [
                self::CRYSTALLITE_ALLOY => ['blueprintTypeId' => self::ALLOY_BLUEPRINT, 'activityId' => self::MANUFACTURING],
                self::CARBON_POLYMERS => ['blueprintTypeId' => self::POLYMERS_BLUEPRINT, 'activityId' => self::MANUFACTURING],
            ],
            [
                self::ALLOY_BLUEPRINT => [['materialTypeId' => self::CARBON_POLYMERS, 'quantity' => 12]],
                self::POLYMERS_BLUEPRINT => [['materialTypeId' => self::CRYSTALLITE_ALLOY, 'quantity' => 9]],
            ],
        );

        $result = $ingredients->producedIngredientsOf([self::CRYSTALLITE_ALLOY]);

        self::assertSame(
            $this->sortedByKey([self::CRYSTALLITE_ALLOY => [self::CARBON_POLYMERS], self::CARBON_POLYMERS => [self::CRYSTALLITE_ALLOY]]),
            $this->sortedByKey($result),
        );
        self::assertSame([[self::CRYSTALLITE_ALLOY], [self::CARBON_POLYMERS]], $this->producerCalls);
    }

    private function ingredientsOf(array $producersByProductTypeId, array $materialsByBlueprintTypeId): SdeChainRecipeIngredients
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findProducersOf')->willReturnCallback(
            function (array $productTypeIds) use ($producersByProductTypeId): array {
                $this->producerCalls[] = $this->sortedValues($productTypeIds);

                return array_intersect_key($producersByProductTypeId, array_flip($productTypeIds));
            },
        );

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds) use ($materialsByBlueprintTypeId): array {
                $this->materialCalls[] = $this->sortedValues($blueprintTypeIds);

                return array_intersect_key($materialsByBlueprintTypeId, array_flip($blueprintTypeIds));
            },
        );

        return new SdeChainRecipeIngredients($productRepository, $materialRepository);
    }

    private function sortedValues(array $typeIds): array
    {
        $values = array_values($typeIds);
        sort($values);

        return $values;
    }

    private function nonEmptyMaterialCalls(): array
    {
        return array_values(array_filter($this->materialCalls, static fn (array $blueprintTypeIds): bool => [] !== $blueprintTypeIds));
    }

    private function sortedByKey(array $ingredients): array
    {
        ksort($ingredients);

        return $ingredients;
    }
}
