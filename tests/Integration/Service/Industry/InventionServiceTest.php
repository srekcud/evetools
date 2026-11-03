<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Enum\IndustryActivityType;
use App\Service\Industry\InventionService;
use App\Tests\Fixtures\Sde\ManufacturableProductSde;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-293')]
final class InventionServiceTest extends IntegrationTestCase
{
    public function testIdentifyT2ProductsOverTheRealRepository(): void
    {
        ManufacturableProductSde::persist($this->em);
        ManufacturableProductSde::persistUnpublishedInventedProductAndInventedReactionFormula($this->em);
        $this->em->clear();
        $inventionService = self::getContainer()->get(InventionService::class);

        self::assertSame([ManufacturableProductSde::HECATE => true], $inventionService->identifyT2Products([
            ['blueprintTypeId' => ManufacturableProductSde::FEROX_BLUEPRINT, 'productTypeId' => ManufacturableProductSde::FEROX, 'outputPerRun' => 1, 'activityId' => IndustryActivityType::Manufacturing->value],
            ['blueprintTypeId' => ManufacturableProductSde::FERNITE_CARBIDE_REACTION_FORMULA, 'productTypeId' => ManufacturableProductSde::FERNITE_CARBIDE, 'outputPerRun' => 10_000, 'activityId' => IndustryActivityType::Reaction->value],
            ['blueprintTypeId' => ManufacturableProductSde::HECATE_BLUEPRINT, 'productTypeId' => ManufacturableProductSde::HECATE, 'outputPerRun' => 1, 'activityId' => IndustryActivityType::Manufacturing->value],
            ['blueprintTypeId' => ManufacturableProductSde::INVENTED_REACTION_FORMULA, 'productTypeId' => ManufacturableProductSde::PRODUCT_OF_AN_INVENTED_REACTION_FORMULA, 'outputPerRun' => 200, 'activityId' => IndustryActivityType::Reaction->value],
        ]));
    }
}
