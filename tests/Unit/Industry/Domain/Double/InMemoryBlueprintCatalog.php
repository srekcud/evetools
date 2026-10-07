<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain\Double;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;

/**
 * BlueprintCatalog built from given recipes, or from the SDE extract of the goldens (tests/Fixtures/Golden/sde-blueprint-recipes.json).
 */
final readonly class InMemoryBlueprintCatalog implements BlueprintCatalog
{
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../../Fixtures/Golden/sde-blueprint-recipes.json';

    /** @var array<int, Recipe> */
    private array $recipesByProduct;

    public function __construct(Recipe ...$recipes)
    {
        $recipesByProduct = [];
        foreach ($recipes as $recipe) {
            $recipesByProduct[$recipe->productTypeId] = $recipe;
        }
        $this->recipesByProduct = $recipesByProduct;
    }

    public static function fromSdeFixture(): self
    {
        $recipes = [];
        foreach (self::sdeFixture()['recipes'] as $productTypeId => $sdeRecipe) {
            $materials = [];
            foreach ($sdeRecipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                $materials[] = new RecipeMaterial((int) $materialTypeId, new Quantity($baseQuantityPerRun));
            }
            $recipes[] = new Recipe(
                productTypeId: (int) $productTypeId,
                activity: 'reaction' === $sdeRecipe['activity'] ? ActivityKind::Reaction : ActivityKind::Manufacturing,
                outputPerRun: new Quantity($sdeRecipe['outputPerRun']),
                materials: $materials,
                maxProductionLimit: new Runs($sdeRecipe['maxProductionLimit']),
                baseTimeSeconds: $sdeRecipe['baseTimeSeconds'],
            );
        }

        return new self(...$recipes);
    }

    /**
     * Golden expectations name their materials; names are unique in the fixture.
     */
    public static function typeIdOf(string $typeName): int
    {
        $typeId = array_search($typeName, self::sdeFixture()['typeNames'], true);
        if (false === $typeId) {
            throw new \OutOfBoundsException(\sprintf('No type named "%s" in the SDE fixture.', $typeName));
        }

        return (int) $typeId;
    }

    public static function nameOf(int $typeId): string
    {
        return self::sdeFixture()['typeNames'][(string) $typeId]
            ?? throw new \OutOfBoundsException(\sprintf('No type %d in the SDE fixture.', $typeId));
    }

    public function recipeFor(int $productTypeId): ?Recipe
    {
        return $this->recipesByProduct[$productTypeId] ?? null;
    }

    /**
     * @return array{
     *     recipes: array<string, array{activity: string, outputPerRun: int, maxProductionLimit: int, baseTimeSeconds: int, materials: array<string, int>}>,
     *     typeNames: array<string, string>,
     * }
     */
    private static function sdeFixture(): array
    {
        static $fixture = null;
        if (null === $fixture) {
            $json = file_get_contents(self::SDE_RECIPES_FIXTURE);
            if (false === $json) {
                throw new \RuntimeException('SDE recipes fixture is missing.');
            }
            $fixture = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $fixture;
    }
}
