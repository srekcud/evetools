<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\JobStructureComparison;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureComparisonRow;
use App\Industry\Application\StructureCostBreakdown;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Service\Industry\Project\StructureComparisonPresenter;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureComparisonPresenter::class)]
#[Group('issue-341')]
final class StructureComparisonPresenterTest extends TestCase
{
    private const string WORKSHOP_NAME = 'Perimeter Raitaru';
    private const string RIGGED_NAME = 'Maurasi Azbel';

    public function testGroupAndRowsArePresentedWithEveryKeyOfTheContract(): void
    {
        $comparison = new JobStructureComparison(
            Example::PRODUCT_TYPE_ID,
            ActivityKind::Manufacturing,
            1,
            10,
            'A',
            StructureChoiceStatus::BestInFavoriteSystem,
            [
                new StructureComparisonRow(
                    Example::workshopStructureWithoutRig(),
                    true,
                    Multiplier::one(),
                    new StructureCostBreakdown($this->known(10_000_000.0), $this->known(900_000.0), $this->known(0.0), 2000.0, 0),
                    null,
                ),
                new StructureComparisonRow(
                    Example::structureC(),
                    false,
                    new Multiplier(0.9),
                    new StructureCostBreakdown($this->known(9_000_000.0), $this->known(850_000.0), $this->known(900_000.0), 2000.0, 3),
                    null,
                ),
            ],
            'C',
            new Isk(150_000.0),
        );

        $presented = StructureComparisonPresenter::of($comparison, 'Ishtar', $this->labels(), $this->systemNames());

        $this->assertSame(
            $this->sorted([
                'typeId' => Example::PRODUCT_TYPE_ID,
                'typeName' => 'Ishtar',
                'activity' => 'manufacturing',
                'jobs' => 1,
                'runs' => 10,
                'chosenStructureId' => 'A',
                'choiceStatus' => 'best_in_favorite_system',
                'cheapestStructureId' => 'C',
                'savingIfCheapest' => 150_000.0,
                'rows' => $presented['rows'] ?? 'absent',
            ]),
            $this->sorted($presented),
        );
        $this->assertCount(2, $presented['rows'] ?? []);
        $this->assertSame(
            $this->sorted([
                'structureId' => 'C',
                'name' => self::RIGGED_NAME,
                'kind' => 'azbel',
                'solarSystemId' => Example::THREE_JUMPS_SYSTEM_ID,
                'solarSystemName' => 'Maurasi',
                'inFavoriteSystem' => false,
                'jumps' => 3,
                'materialBonusPercent' => 10.0,
                'facilityTaxPercent' => 0.5,
                'materialsCost' => 9_000_000.0,
                'installationCost' => 850_000.0,
                'transportCost' => 900_000.0,
                'transportedCubicMeters' => 2000.0,
                'totalCost' => 10_750_000.0,
                'delta' => null,
                'missingData' => [],
            ]),
            $this->sorted($presented['rows'][1] ?? []),
        );
        $this->assertSame(['A', true, 0, 0.0, 1.0, 10_900_000.0], $this->pick($presented['rows'][0] ?? [], 'structureId', 'inFavoriteSystem', 'jumps', 'materialBonusPercent', 'facilityTaxPercent', 'totalCost'));
    }

    public function testAmountsAreRoundedToTwoDecimals(): void
    {
        $comparison = $this->comparisonOf(
            new StructureCostBreakdown($this->known(1_234.5678), $this->known(99.994), $this->known(12.3456), 1_234.5, 3),
            new Isk(150_000.456),
        );

        $presented = StructureComparisonPresenter::of($comparison, 'Ishtar', $this->labels(), $this->systemNames());

        $this->assertSame(
            [150_000.46, [1_234.57, 99.99, 12.35, 1_346.91]],
            [
                $presented['savingIfCheapest'] ?? 'absent',
                $this->pick($presented['rows'][0] ?? [], 'materialsCost', 'installationCost', 'transportCost', 'totalCost'),
            ],
        );
    }

    public function testUnknownCostsNamesAndSystemArePresentedAsNullNeverZeroWithTheMissingDataOfTheSum(): void
    {
        $comparison = $this->comparisonOf(
            new StructureCostBreakdown(
                Cost::unknown(MissingData::marketPrice(Example::MATERIAL_TYPE_ID)),
                $this->known(850_000.0),
                Cost::unknown(MissingData::jumpRoute()),
                null,
                null,
            ),
            null,
            Example::riggedStructure('system-unknown', null),
        );

        $presented = StructureComparisonPresenter::of($comparison, null, [], []);

        $this->assertSame(
            [
                null, null,
                'system-unknown', null, null, null, null, null, null,
                null, 850_000.0, null, null, null,
                [['reason' => 'market_price', 'typeId' => Example::MATERIAL_TYPE_ID], ['reason' => 'jump_route', 'typeId' => null]],
            ],
            [...$this->pick($presented, 'typeName', 'savingIfCheapest'), ...$this->pick(
                $presented['rows'][0] ?? [],
                'structureId', 'name', 'kind', 'solarSystemId', 'solarSystemName', 'jumps', 'transportedCubicMeters',
                'materialsCost', 'installationCost', 'transportCost', 'totalCost', 'delta', 'missingData',
            )],
        );
    }

    public function testMaterialBonusPercentIsOneMinusTheMaterialMultiplierTimesOneHundredAndTheFacilityTaxIsAPercent(): void
    {
        $breakdown = new StructureCostBreakdown($this->known(1_000.0), $this->known(100.0), $this->known(10.0), 10.0, 3);
        $comparison = new JobStructureComparison(
            Example::PRODUCT_TYPE_ID,
            ActivityKind::Manufacturing,
            1,
            1,
            'C',
            StructureChoiceStatus::Assigned,
            [
                new StructureComparisonRow(Example::structureC(), false, new Multiplier(0.9), $breakdown, null),
                new StructureComparisonRow(Example::unriggedStructure('E', Example::WORKSHOP_SYSTEM_ID, 0.0125), true, new Multiplier(0.976), $breakdown, null),
            ],
            null,
            null,
        );

        $presented = StructureComparisonPresenter::of($comparison, 'Ishtar', $this->labels(), $this->systemNames());

        $this->assertSame(
            [[10.0, 0.5], [2.4, 1.25]],
            array_map(fn (array $row): array => $this->pick($row, 'materialBonusPercent', 'facilityTaxPercent'), $presented['rows'] ?? []),
        );
    }

    #[Group('issue-780')]
    public function testStructureWithoutFacilityTaxIsPresentedWithANullFacilityTaxPercentAndAZeroTaxWithZero(): void
    {
        $breakdown = new StructureCostBreakdown($this->known(1_000.0), $this->known(100.0), $this->known(10.0), 10.0, 3);
        $comparison = new JobStructureComparison(
            Example::PRODUCT_TYPE_ID,
            ActivityKind::Manufacturing,
            1,
            1,
            null,
            StructureChoiceStatus::NotConfigured,
            [
                new StructureComparisonRow(Example::unriggedStructure('E', Example::WORKSHOP_SYSTEM_ID, null), false, Multiplier::one(), $breakdown, null),
                new StructureComparisonRow(Example::unriggedStructure('F', Example::WORKSHOP_SYSTEM_ID, 0.0), false, Multiplier::one(), $breakdown, null),
            ],
            null,
            null,
        );

        $presented = StructureComparisonPresenter::of($comparison, 'Ishtar', $this->labels(), $this->systemNames());

        $this->assertArrayHasKey('facilityTaxPercent', $presented['rows'][0]);
        $this->assertNull($presented['rows'][0]['facilityTaxPercent']);
        $this->assertArrayHasKey('facilityTaxPercent', $presented['rows'][1]);
        $this->assertSame(0.0, $presented['rows'][1]['facilityTaxPercent']);
    }

    public function testEveryChoiceStatusHasItsContractName(): void
    {
        $presentedStatuses = [];
        foreach (StructureChoiceStatus::cases() as $status) {
            $comparison = new JobStructureComparison(Example::PRODUCT_TYPE_ID, ActivityKind::Reaction, 2, 5, null, $status, [], null, null);
            $presented = StructureComparisonPresenter::of($comparison, 'Fernite Composite', [], []);
            $presentedStatuses[] = [$presented['activity'] ?? 'absent', $presented['choiceStatus'] ?? 'absent', $presented['rows'] ?? 'absent'];
        }

        $this->assertSame(
            [
                ['reaction', 'assigned', []],
                ['reaction', 'best_in_favorite_system', []],
                ['reaction', 'best_configured', []],
                ['reaction', 'best_outside_favorite_system', []],
                ['reaction', 'best_by_total_cost', []],
                ['reaction', 'not_configured', []],
            ],
            $presentedStatuses,
        );
    }

    private function comparisonOf(StructureCostBreakdown $breakdown, ?Isk $savingIfCheapest, ?ProductionStructure $structure = null): JobStructureComparison
    {
        $structure ??= Example::workshopStructureWithoutRig();

        return new JobStructureComparison(
            Example::PRODUCT_TYPE_ID,
            ActivityKind::Manufacturing,
            1,
            10,
            null,
            StructureChoiceStatus::NotConfigured,
            [new StructureComparisonRow($structure, false, Multiplier::one(), $breakdown, null)],
            null,
            $savingIfCheapest,
        );
    }

    private function known(float $amount): Cost
    {
        return Cost::known(new Isk($amount));
    }

    /**
     * @return array<string, array{name: string, kind: string}>
     */
    private function labels(): array
    {
        return [
            'A' => ['name' => self::WORKSHOP_NAME, 'kind' => 'raitaru'],
            'C' => ['name' => self::RIGGED_NAME, 'kind' => 'azbel'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function systemNames(): array
    {
        return [
            Example::WORKSHOP_SYSTEM_ID => 'Perimeter',
            Example::THREE_JUMPS_SYSTEM_ID => 'Maurasi',
        ];
    }

    /**
     * @param array<string, mixed> $presented
     *
     * @return array<string, mixed>
     */
    private function sorted(array $presented): array
    {
        ksort($presented);

        return $presented;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    private function pick(array $row, string ...$keys): array
    {
        return array_map(static fn (string $key): mixed => \array_key_exists($key, $row) ? $row[$key] : 'absent', $keys);
    }
}
