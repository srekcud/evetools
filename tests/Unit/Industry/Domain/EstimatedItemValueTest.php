<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\EivMaterial;
use App\Industry\Domain\EstimatedItemValue;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Spec R5: EIV(activity, runs) = runs × Σ (base quantity × adjusted price).
 *
 * Base quantities are the SDE ones, before ME and before any structure or rig bonus: the signature takes no ME.
 * For copying and invention the caller passes the manufacturing materials of the copied or invented blueprint.
 */
#[CoversClass(EstimatedItemValue::class)]
#[CoversClass(EivMaterial::class)]
final class EstimatedItemValueTest extends TestCase
{
    private const string GOLDEN_DIRECTORY = __DIR__.'/../../../Fixtures/Golden';

    public function testEivIsRunsTimesTheSumOfBaseQuantitiesAtAdjustedPrice(): void
    {
        // 10 × (1 000 × 4.0 + 500 × 10.0) = 90 000.
        $eiv = EstimatedItemValue::of(new Runs(10), [
            new EivMaterial(34, new Quantity(1000), new Isk(4.0)),
            new EivMaterial(36, new Quantity(500), new Isk(10.0)),
        ]);

        $this->assertTrue($eiv->isKnown());
        $this->assertEqualsWithDelta(90000.0, $eiv->amount()->amount, 1.0);
    }

    public function testEivOfASingleRunIsTheSumOfBaseQuantitiesAtAdjustedPrice(): void
    {
        $eiv = EstimatedItemValue::of(new Runs(1), [
            new EivMaterial(34, new Quantity(1000), new Isk(4.0)),
        ]);

        $this->assertEqualsWithDelta(4000.0, $eiv->amount()->amount, 1.0);
    }

    public function testMissingAdjustedPriceMakesTheEivUnknownWithTheTypeIdListed(): void
    {
        // §4.5 vector "missing-adjusted-price": 1 000 × 4.0 + 500 × absent. Today evetools returns 4 000 silently.
        $vector = self::installCostVector('missing-adjusted-price');
        $materials = array_map(
            static fn (array $material): EivMaterial => new EivMaterial(
                $material['typeId'],
                new Quantity($material['baseQuantity']),
                null === $material['adjustedPrice'] ? null : new Isk((float) $material['adjustedPrice']),
            ),
            $vector['materials'],
        );

        $eiv = EstimatedItemValue::of(new Runs($vector['runs']), $materials);

        $this->assertFalse($eiv->isKnown());
        $this->assertEquals([MissingData::adjustedPrice(35)], $eiv->missingData);
    }

    public function testEveryMaterialWithoutAdjustedPriceIsListedInMaterialOrder(): void
    {
        $eiv = EstimatedItemValue::of(new Runs(3), [
            new EivMaterial(36, new Quantity(10), null),
            new EivMaterial(34, new Quantity(1000), new Isk(4.0)),
            new EivMaterial(35, new Quantity(500), null),
        ]);

        $this->assertFalse($eiv->isKnown());
        $this->assertEquals([MissingData::adjustedPrice(36), MissingData::adjustedPrice(35)], $eiv->missingData);
    }

    public function testZeroAdjustedPriceIsAKnownPriceNotAMissingOne(): void
    {
        $eiv = EstimatedItemValue::of(new Runs(2), [
            new EivMaterial(34, new Quantity(1000), new Isk(4.0)),
            new EivMaterial(35, new Quantity(500), new Isk(0.0)),
        ]);

        $this->assertTrue($eiv->isKnown());
        $this->assertEqualsWithDelta(8000.0, $eiv->amount()->amount, 1.0);
    }

    /**
     * @return array<string, mixed>
     */
    private static function installCostVector(string $name): array
    {
        $json = file_get_contents(self::GOLDEN_DIRECTORY.'/install-cost-vectors.json');
        self::assertIsString($json, 'Golden fixture install-cost-vectors.json is missing');
        $vectors = json_decode($json, true, 512, \JSON_THROW_ON_ERROR)['vectors'];

        foreach ($vectors as $vector) {
            if ($name === $vector['name']) {
                return $vector;
            }
        }

        self::fail("Install cost vector {$name} not found");
    }
}
