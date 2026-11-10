<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\RigCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RigCatalogue::class)]
#[Group('issue-523')]
final class RigCatalogueTest extends TestCase
{
    private const string LABORATORY_CATEGORY = 'Laboratory';

    private const array SDE_TYPE_ID_BY_LABORATORY_RIG_NAME = [
        'Standup L-Set Blueprint Copy Optimization I' => 43729,
        'Standup L-Set Blueprint Copy Optimization II' => 43730,
        'Standup M-Set Blueprint Copy Accelerator I' => 43893,
        'Standup M-Set Blueprint Copy Accelerator II' => 43892,
        'Standup M-Set Blueprint Copy Cost Optimization I' => 43891,
        'Standup M-Set Blueprint Copy Cost Optimization II' => 43890,
        'Standup XL-Set Laboratory Optimization I' => 37183,
        'Standup XL-Set Laboratory Optimization II' => 37182,
    ];

    public function testMAndLSetLaboratoryOptimizationNamesMissingFromTheSdeAreNoLongerOffered(): void
    {
        $catalogueRigNames = array_keys(self::catalogueRigsByName());

        foreach ([
            'Standup M-Set Laboratory Optimization I',
            'Standup M-Set Laboratory Optimization II',
            'Standup L-Set Laboratory Optimization I',
            'Standup L-Set Laboratory Optimization II',
        ] as $removedRigName) {
            $this->assertNotContains($removedRigName, $catalogueRigNames);
        }
    }

    public function testBlueprintCopyRigsOfTheSdeAreOfferedAsManufacturingFamilyLaboratoryRigsWithTheirTimeBonus(): void
    {
        $expectedLaboratoryRigs = [
            'Standup M-Set Blueprint Copy Accelerator I' => self::laboratoryRig('Standup M-Set Blueprint Copy Accelerator I', 'M', 20.0),
            'Standup M-Set Blueprint Copy Accelerator II' => self::laboratoryRig('Standup M-Set Blueprint Copy Accelerator II', 'M', 24.0),
            'Standup M-Set Blueprint Copy Cost Optimization I' => self::laboratoryRig('Standup M-Set Blueprint Copy Cost Optimization I', 'M', null),
            'Standup M-Set Blueprint Copy Cost Optimization II' => self::laboratoryRig('Standup M-Set Blueprint Copy Cost Optimization II', 'M', null),
            'Standup L-Set Blueprint Copy Optimization I' => self::laboratoryRig('Standup L-Set Blueprint Copy Optimization I', 'L', 20.0),
            'Standup L-Set Blueprint Copy Optimization II' => self::laboratoryRig('Standup L-Set Blueprint Copy Optimization II', 'L', 24.0),
            'Standup XL-Set Laboratory Optimization I' => self::laboratoryRig('Standup XL-Set Laboratory Optimization I', 'XL', 20.0),
            'Standup XL-Set Laboratory Optimization II' => self::laboratoryRig('Standup XL-Set Laboratory Optimization II', 'XL', 24.0),
        ];
        ksort($expectedLaboratoryRigs);

        $offeredLaboratoryRigs = array_map(
            static function (array $rig): array {
                ksort($rig);

                return $rig;
            },
            array_filter(self::catalogueRigsByName('manufacturing'), static fn (array $rig): bool => self::LABORATORY_CATEGORY === $rig['category']),
        );
        ksort($offeredLaboratoryRigs);

        $this->assertSame($expectedLaboratoryRigs, $offeredLaboratoryRigs);
    }

    public function testEveryLaboratoryRigNameOfTheCatalogueIsARigTypeOfTheSde(): void
    {
        $laboratoryRigNames = array_keys(array_filter(
            self::catalogueRigsByName(),
            static fn (array $rig): bool => self::LABORATORY_CATEGORY === $rig['category'],
        ));
        sort($laboratoryRigNames);

        $sdeRigNames = array_keys(self::SDE_TYPE_ID_BY_LABORATORY_RIG_NAME);
        sort($sdeRigNames);

        $this->assertSame($sdeRigNames, $laboratoryRigNames);
    }

    private static function laboratoryRig(string $name, string $size, ?float $timeBonus): array
    {
        $rig = ['name' => $name, 'bonus' => 0, 'category' => self::LABORATORY_CATEGORY, 'size' => $size, 'targetCategories' => []];
        if (null !== $timeBonus) {
            $rig['timeBonus'] = $timeBonus;
        }
        ksort($rig);

        return $rig;
    }

    private static function catalogueRigsByName(?string $onlyFamily = null): array
    {
        $rigsByName = [];
        foreach (RigCatalogue::RIGS_BY_FAMILY as $family => $rigs) {
            if (null !== $onlyFamily && $onlyFamily !== $family) {
                continue;
            }
            foreach ($rigs as $rig) {
                $rigsByName[(string) $rig['name']] = $rig;
            }
        }

        return $rigsByName;
    }
}
