<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStructureConfig;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\MaterialQuantityFormula;
use App\Tests\Unit\Industry\MaterialQuantityEquivalenceTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryBonusService::class)]
#[CoversClass(MaterialQuantityFormula::class)]
final class StackedRigsMaterialQuantityTest extends TestCase
{
    private const string MANUFACTURING_CATEGORY = 'basic_capital_component';
    private const string REACTION_CATEGORY = 'composite_reaction';

    private const array MANUFACTURING_RIG_NAMES = [
        '2.0x2.1' => 'Standup XL-Set Structure and Component Manufacturing Efficiency I',
        '2.4x2.1' => 'Standup XL-Set Structure and Component Manufacturing Efficiency II',
    ];

    private const array REACTION_RIG_NAMES = [
        '2.4x1.1' => 'Standup L-Set Reactor Efficiency II',
    ];

    private const array MANUFACTURING_STRUCTURE_TYPES = [
        'no structure bonus' => 'station',
        'structure bonus 1 %' => 'raitaru',
    ];

    private IndustryBonusService $bonusService;

    protected function setUp(): void
    {
        $this->bonusService = new IndustryBonusService(
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }

    #[DataProviderExternal(MaterialQuantityEquivalenceTest::class, 'multipleRigsManufacturingProvider')]
    #[Group('issue-249')]
    public function testStackedRigsGiveTheSameQuantityAsTheCoreIssue249(
        int $baseQuantityPerRun,
        int $runs,
        int $meLevel,
        string $structureLabel,
        array $rigs,
        float $unusedLegacyRigBonusPercent,
        int $unusedLegacyQuantity,
        int $expectedCoreQuantity,
    ): void {
        $structure = $this->structure(
            self::MANUFACTURING_STRUCTURE_TYPES[$structureLabel],
            array_map(static fn (array $rig): string => self::MANUFACTURING_RIG_NAMES[sprintf('%.1fx%.1f', $rig[0], $rig[1])], $rigs),
        );

        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, self::MANUFACTURING_CATEGORY);

        $this->assertSame(
            $expectedCoreQuantity,
            MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, $meLevel, $bonus['base'], $bonus['rig']),
        );
    }

    #[DataProviderExternal(MaterialQuantityEquivalenceTest::class, 'multipleRigsReactionProvider')]
    #[Group('issue-249')]
    public function testStackedReactionRigsGiveTheSameQuantityAsTheCoreIssue249(
        int $baseQuantityPerRun,
        int $runs,
        array $rigs,
        float $unusedLegacyRigBonusPercent,
        int $unusedLegacyQuantity,
        int $expectedCoreQuantity,
    ): void {
        $structure = $this->structure(
            'athanor',
            array_map(static fn (array $rig): string => self::REACTION_RIG_NAMES[sprintf('%.1fx%.1f', $rig[0], $rig[1])], $rigs),
        );

        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, self::REACTION_CATEGORY);

        $this->assertSame(
            $expectedCoreQuantity,
            MaterialQuantityFormula::forJob($baseQuantityPerRun, $runs, 0, $bonus['base'], $bonus['rig']),
        );
    }

    private function structure(string $type, array $rigNames): IndustryStructureConfig
    {
        $structure = new IndustryStructureConfig();
        $structure->setName('Test structure');
        $structure->setStructureType($type);
        $structure->setSecurityType('nullsec');
        $structure->setRigs($rigNames);

        return $structure;
    }
}
