<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Sde;

use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeBlueprintCatalog::class)]
final class SdeBlueprintCatalogOutputPerRunTest extends TestCase
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_REACTION = 11;

    private const int SYNTHETIC_PRODUCT = 990_000_001;
    private const int SYNTHETIC_BLUEPRINT = 990_000_002;
    private const int SYNTHETIC_BLUEPRINT_MAX_PRODUCTION_LIMIT = 300;
    private const int SYNTHETIC_BLUEPRINT_BASE_TIME_SECONDS = 600;
    private const int SYNTHETIC_REACTION_FORMULA_MAX_PRODUCTION_LIMIT = 1_000;
    private const int SYNTHETIC_REACTION_FORMULA_BASE_TIME_SECONDS = 10_800;

    public function testProductYieldingSeveralUnitsPerRunKeepsItsOutputPerRun(): void
    {
        $catalog = $this->catalogWithOneProducer(self::ACTIVITY_MANUFACTURING, 25, self::SYNTHETIC_BLUEPRINT_MAX_PRODUCTION_LIMIT, self::SYNTHETIC_BLUEPRINT_BASE_TIME_SECONDS);

        $recipe = $catalog->recipeFor(self::SYNTHETIC_PRODUCT);

        $this->assertNotNull($recipe);
        $this->assertSame(25, $recipe->outputPerRun->value);
        $this->assertSame(self::SYNTHETIC_BLUEPRINT_MAX_PRODUCTION_LIMIT, $recipe->maxProductionLimit->value);
    }

    public function testBlueprintYieldingZeroUnitPerRunIsRefusedWhenItsRecipeIsRead(): void
    {
        $catalog = $this->catalogWithOneProducer(self::ACTIVITY_MANUFACTURING, 0, self::SYNTHETIC_BLUEPRINT_MAX_PRODUCTION_LIMIT, self::SYNTHETIC_BLUEPRINT_BASE_TIME_SECONDS);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A recipe yields at least 1 unit per run, got 0.');

        $catalog->recipeFor(self::SYNTHETIC_PRODUCT);
    }

    public function testReactionFormulaYieldingZeroUnitPerRunIsRefusedWhenItsRecipeIsRead(): void
    {
        $catalog = $this->catalogWithOneProducer(self::ACTIVITY_REACTION, 0, self::SYNTHETIC_REACTION_FORMULA_MAX_PRODUCTION_LIMIT, self::SYNTHETIC_REACTION_FORMULA_BASE_TIME_SECONDS);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A recipe yields at least 1 unit per run, got 0.');

        $catalog->recipeFor(self::SYNTHETIC_PRODUCT);
    }

    private function catalogWithOneProducer(int $activityId, int $outputPerRun, int $maxProductionLimit, int $baseTimeSeconds): SdeBlueprintCatalog
    {
        $producer = (new IndustryActivityProduct())
            ->setTypeId(self::SYNTHETIC_BLUEPRINT)
            ->setActivityId($activityId)
            ->setProductTypeId(self::SYNTHETIC_PRODUCT)
            ->setQuantity($outputPerRun);
        $blueprint = (new IndustryBlueprint())
            ->setTypeId(self::SYNTHETIC_BLUEPRINT)
            ->setMaxProductionLimit($maxProductionLimit);
        $blueprintActivity = (new IndustryActivity())
            ->setTypeId(self::SYNTHETIC_BLUEPRINT)
            ->setActivityId($activityId)
            ->setTime($baseTimeSeconds);

        $activityProducts = $this->createStub(IndustryActivityProductRepository::class);
        $activityProducts->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $requestedActivityId): ?IndustryActivityProduct => self::SYNTHETIC_PRODUCT === $productTypeId && $activityId === $requestedActivityId ? $producer : null,
        );
        $activities = $this->createStub(IndustryActivityRepository::class);
        $activities->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivity => ['typeId' => self::SYNTHETIC_BLUEPRINT, 'activityId' => $activityId] === $criteria ? $blueprintActivity : null,
        );
        $materials = $this->createStub(IndustryActivityMaterialRepository::class);
        $materials->method('findByBlueprintAndActivity')->willReturn([]);
        $blueprints = $this->createStub(IndustryBlueprintRepository::class);
        $blueprints->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?IndustryBlueprint => self::SYNTHETIC_BLUEPRINT === $typeId ? $blueprint : null,
        );

        return new SdeBlueprintCatalog($activityProducts, $activities, $materials, $blueprints);
    }
}
