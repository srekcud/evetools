<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MaterialQuantity;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every job of the goldens (tests/Fixtures/Golden), computed in isolation with R1/R2.
 *
 * The job runs come from the golden (already aggregated by R3); only the per-job material rule is checked here.
 * Material modifiers are passed explicitly: the golden fixes the formula, not the structure selection.
 */
#[CoversClass(MaterialQuantity::class)]
final class GoldenJobMaterialQuantityTest extends TestCase
{
    private const string GOLDEN_DIRECTORY = __DIR__.'/../../../Fixtures/Golden';

    #[DataProvider('goldenJobMaterialProvider')]
    public function testJobMaterialQuantityMatchesGolden(
        string $activity,
        int $baseQuantityPerRun,
        int $runs,
        int $meLevel,
        float $materialModifier,
        int $expectedQuantity,
    ): void {
        $quantity = 'reaction' === $activity
            ? MaterialQuantity::forReactionJob(
                new Quantity($baseQuantityPerRun),
                new Runs($runs),
                new Multiplier($materialModifier),
            )
            : MaterialQuantity::forManufacturingJob(
                new Quantity($baseQuantityPerRun),
                new Runs($runs),
                new MaterialEfficiency($meLevel),
                new Multiplier($materialModifier),
            );

        $this->assertSame($expectedQuantity, $quantity->value);
    }

    /**
     * @return iterable<string, array{string, int, int, int, float, int}>
     */
    public static function goldenJobMaterialProvider(): iterable
    {
        $nitrogenFuelBlock = self::loadGolden('nitrogen-fuel-block-25runs-me10-npc.json');
        yield from self::jobMaterials(
            'nitrogen-fuel-block root (NPC station)',
            $nitrogenFuelBlock['expected']['root'],
            $nitrogenFuelBlock['input']['me'],
            [],
        );

        $hailL = self::loadGolden('hail-l-10runs-me2-raitaru-nullsec.json');
        $hailLRootStructure = $hailL['input']['structures']['root'];
        yield from self::jobMaterials(
            'hail-l root (Raitaru, T2 rig, nullsec)',
            $hailL['expected']['root'],
            $hailL['input']['me'],
            [$hailLRootStructure['structureMaterialModifier'], $hailLRootStructure['rigMaterialModifier']],
        );
        foreach ($hailL['expected']['intermediates'] as $intermediate) {
            yield from self::jobMaterials('hail-l intermediate', $intermediate, $intermediate['me'], $intermediate['modifiers']);
        }

        $ferniteCarbide = self::loadGolden('fernite-carbide-10runs-tatara-nullsec.json');
        $reactionStructure = $ferniteCarbide['input']['structures']['reactions'];
        yield from self::jobMaterials(
            'fernite-carbide root (Tatara, T2 reaction rig, nullsec)',
            $ferniteCarbide['expected']['root'],
            $ferniteCarbide['input']['me'],
            [$reactionStructure['structureMaterialModifier'], $reactionStructure['rigMaterialModifier']],
        );
        foreach ($ferniteCarbide['expected']['intermediates'] as $intermediate) {
            yield from self::jobMaterials('fernite-carbide intermediate', $intermediate, $intermediate['me'], $intermediate['modifiers']);
        }
    }

    /**
     * @param array{product: string, runs: int, inputs: array<string, int>} $goldenJob
     * @param list<float|int>                                                $modifiers
     *
     * @return iterable<string, array{string, int, int, int, float, int}>
     */
    private static function jobMaterials(string $caseName, array $goldenJob, int $meLevel, array $modifiers): iterable
    {
        $product = self::sdeBaseMaterials()[$goldenJob['product']];
        $materialModifier = (float) array_product($modifiers);

        foreach ($goldenJob['inputs'] as $materialName => $expectedQuantity) {
            yield \sprintf('%s: %s, %d runs -> %s', $caseName, $goldenJob['product'], $goldenJob['runs'], $materialName) => [
                $product['activity'],
                $product['materials'][$materialName],
                $goldenJob['runs'],
                $meLevel,
                $materialModifier,
                $expectedQuantity,
            ];
        }
    }

    /**
     * @return array<string, array{activity: string, materials: array<string, int>}>
     */
    private static function sdeBaseMaterials(): array
    {
        return self::loadGolden('sde-base-materials.json')['products'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadGolden(string $fileName): array
    {
        $json = file_get_contents(self::GOLDEN_DIRECTORY.'/'.$fileName);
        self::assertIsString($json, "Golden fixture {$fileName} is missing");

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
