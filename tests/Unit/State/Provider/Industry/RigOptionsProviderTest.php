<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryStructureConfig;
use App\State\Provider\Industry\RigOptionsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #93: the rig catalog the API sends to the structure form states exactly the bonuses
 * frozen in Golden/rig-bonuses-highsec.json, so the form displays them without any table of its own.
 * `bonus` is the material bonus, `categoryBonuses` overrides it per category, `timeBonus` is the
 * time bonus (absent when the rig has none).
 *
 * Measured in a highsec NPC station: no structure base bonus, x1.0 security modifier for
 * standard rigs, the Thukker highsec modifier for Thukker rigs.
 */
#[CoversClass(RigOptionsProvider::class)]
final class RigOptionsProviderTest extends TestCase
{
    private const string SECURITY_TYPE = 'highsec';
    private const float STANDARD_HIGHSEC_MULTIPLIER = 1.0;

    /** Sorted, as the assertions compare them with the sorted target categories of a rig */
    private const array ALL_CATEGORIES = [
        'advanced_component', 'advanced_large_ship', 'advanced_medium_ship', 'advanced_small_ship', 'ammunition',
        'basic_capital_component', 'basic_large_ship', 'basic_medium_ship', 'basic_small_ship', 'biochemical_reaction',
        'capital_ship', 'composite_reaction', 'drone', 'equipment', 'fighter', 'hybrid_reaction', 'structure',
        'structure_component',
    ];

    private const string FROZEN_BONUSES_PATH = '/Fixtures/Golden/rig-bonuses-highsec.json';

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rigCategoryProvider(): iterable
    {
        foreach ((new RigOptionsProvider())->getRigOptionsArray() as $rigs) {
            foreach ($rigs as $rig) {
                assert(is_string($rig['name']) && is_array($rig['targetCategories']));
                foreach ($rig['targetCategories'] as $category) {
                    assert(is_string($category));
                    yield $rig['name'].' / '.$category => [$rig, $category];
                }
            }
        }
    }

    /** @param array<string, mixed> $rig */
    #[DataProvider('rigCategoryProvider')]
    public function testMaterialBonusMatchesTheFrozenEngineValue(array $rig, string $category): void
    {
        $categoryBonuses = $rig['categoryBonuses'] ?? [];
        assert(is_array($categoryBonuses));
        $materialBonus = $categoryBonuses[$category] ?? $rig['bonus'];
        assert(is_float($materialBonus) || is_int($materialBonus));

        $this->assertSame(round($materialBonus * $this->securityMultiplierOf($rig), 2), $this->frozenBonusOf($rig, $category)['material']);
    }

    /** @param array<string, mixed> $rig */
    #[DataProvider('rigCategoryProvider')]
    public function testTimeBonusMatchesTheFrozenEngineValue(array $rig, string $category): void
    {
        $timeBonus = $rig['timeBonus'] ?? 0.0;
        assert(is_float($timeBonus) || is_int($timeBonus));

        $this->assertSame(round($timeBonus * $this->securityMultiplierOf($rig), 2), $this->frozenBonusOf($rig, $category)['time']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rigProvider(): iterable
    {
        foreach ((new RigOptionsProvider())->getRigOptionsArray() as $rigs) {
            foreach ($rigs as $rig) {
                assert(is_string($rig['name']));
                yield $rig['name'] => [$rig];
            }
        }
    }

    /** @param array<string, mixed> $rig */
    #[DataProvider('rigProvider')]
    public function testTargetCategoriesAreTheFrozenOnesTheEngineAppliesTheRigTo(array $rig): void
    {
        assert(is_string($rig['name']));
        $frozenCategories = array_keys($this->frozenBonuses()[$rig['name']]);
        sort($frozenCategories);

        $targetCategories = $rig['targetCategories'];
        assert(is_array($targetCategories));
        sort($targetCategories);
        $this->assertSame($frozenCategories, $targetCategories);
    }

    public function testLaboratoryRigsHaveNoMaterialBonus(): void
    {
        $laboratoryRigs = array_filter($this->rigsByName(), static fn (array $rig): bool => $rig['category'] === 'Laboratory');

        $this->assertCount(6, $laboratoryRigs);
        foreach ($laboratoryRigs as $rig) {
            $this->assertSame(0, $rig['bonus']);
        }
    }

    public function testEveryStructureSizeIsOfferedItsWholeRigSet(): void
    {
        $countBySize = [];
        foreach ((new RigOptionsProvider())->getRigOptionsArray() as $type => $rigs) {
            foreach ($rigs as $rig) {
                assert(is_string($rig['size']));
                $key = $type.' '.$rig['size'];
                $countBySize[$key] = ($countBySize[$key] ?? 0) + 1;
            }
        }

        $this->assertSame(
            ['manufacturing M' => 52, 'manufacturing L' => 30, 'manufacturing XL' => 9, 'reaction M' => 6, 'reaction L' => 2],
            $countBySize,
        );
    }

    public function testLargeEfficiencyRigsStateTheirTimeBonus(): void
    {
        $rigs = $this->rigsByName();

        $this->assertSame(20.0, $rigs['Standup L-Set Basic Capital Component Manufacturing Efficiency I']['timeBonus'] ?? null);
        $this->assertSame(24.0, $rigs['Standup XL-Set Ship Manufacturing Efficiency II']['timeBonus'] ?? null);
        $this->assertSame(24.0, $rigs['Standup L-Set Reactor Efficiency II']['timeBonus'] ?? null);
        $this->assertArrayNotHasKey('timeBonus', $rigs['Standup M-Set Composite Reactor Material Efficiency II']);
    }

    /**
     * @param array<string, mixed> $rig
     *
     * @return array{material: float, time: float}
     */
    private function frozenBonusOf(array $rig, string $category): array
    {
        assert(is_string($rig['name']));
        $frozenBonuses = $this->frozenBonuses();
        $this->assertArrayHasKey($rig['name'], $frozenBonuses);
        $this->assertArrayHasKey($category, $frozenBonuses[$rig['name']]);

        return $frozenBonuses[$rig['name']][$category];
    }

    /** @return array<string, array<string, array{material: float, time: float}>> */
    private function frozenBonuses(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 4).self::FROZEN_BONUSES_PATH), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }

    /** @param array<string, mixed> $rig */
    private function securityMultiplierOf(array $rig): float
    {
        assert(is_string($rig['name']));

        return str_contains($rig['name'], 'Thukker')
            ? IndustryStructureConfig::THUKKER_RIG_SECURITY_MULTIPLIERS[self::SECURITY_TYPE]
            : self::STANDARD_HIGHSEC_MULTIPLIER;
    }

    /** @return array<string, array<string, mixed>> */
    private function rigsByName(): array
    {
        $byName = [];
        foreach ((new RigOptionsProvider())->getRigOptionsArray() as $rigs) {
            foreach ($rigs as $rig) {
                assert(is_string($rig['name']));
                $byName[$rig['name']] = $rig;
            }
        }

        return $byName;
    }
}
