<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StructureBonusPreviewResource;
use App\ApiResource\Input\Industry\StructureBonusPreviewInput;
use App\Entity\IndustryStructureConfig;
use App\State\Processor\Industry\StructureBonusPreviewProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #93: the structure form previews the bonuses of a configuration not saved yet. The
 * backend computes them, exactly as it does for a saved structure, instead of the frontend
 * recomputing them with its own tables.
 */
#[CoversClass(StructureBonusPreviewProcessor::class)]
final class StructureBonusPreviewProcessorTest extends TestCase
{
    private const string THUKKER_XL_RIG = 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency';
    private const string REACTOR_EFFICIENCY_L_RIG = 'Standup L-Set Reactor Efficiency II';
    private const string COMPOSITE_REACTOR_ME_M_RIG = 'Standup M-Set Composite Reactor Material Efficiency II';

    /** @return iterable<string, array{string, string, string[], float, float, float, float}> */
    public static function configurationProvider(): iterable
    {
        // structure, security, rigs, ME manufacturing, TE manufacturing, ME reactions, TE reactions
        yield 'Athanor nullsec: no reaction time bonus' => ['athanor', 'nullsec', [self::COMPOSITE_REACTOR_ME_M_RIG], 0.0, 0.0, 2.64, 0.0];
        yield 'Tatara nullsec: 25 % base and 24 % x 1.1 rig' => ['tatara', 'nullsec', [self::REACTOR_EFFICIENCY_L_RIG], 0.0, 0.0, 2.64, 44.8];
        yield 'Sotiyo lowsec, Thukker rig x1.9' => ['sotiyo', 'lowsec', [self::THUKKER_XL_RIG], 3.8, 56.6, 0.0, 0.0];
        yield 'Sotiyo nullsec, Thukker rig x0.1' => ['sotiyo', 'nullsec', [self::THUKKER_XL_RIG], 0.2, 31.4, 0.0, 0.0];
    }

    /** @param string[] $rigs */
    #[DataProvider('configurationProvider')]
    public function testPreviewReturnsTheBonusesOfTheConfiguration(
        string $structureType,
        string $securityType,
        array $rigs,
        float $manufacturingMaterialBonus,
        float $manufacturingTimeBonus,
        float $reactionMaterialBonus,
        float $reactionTimeBonus,
    ): void {
        $preview = $this->preview($structureType, $securityType, $rigs);

        $this->assertSame($manufacturingMaterialBonus, $preview->manufacturingMaterialBonus);
        $this->assertSame($manufacturingTimeBonus, $preview->manufacturingTimeBonus);
        $this->assertSame($reactionMaterialBonus, $preview->reactionMaterialBonus);
        $this->assertSame($reactionTimeBonus, $preview->reactionTimeBonus);
    }

    /** @return iterable<string, array{string, string, string[]}> */
    public static function savedStructureProvider(): iterable
    {
        foreach (self::configurationProvider() as $label => [$structureType, $securityType, $rigs]) {
            yield $label => [$structureType, $securityType, $rigs];
        }
    }

    /** @param string[] $rigs */
    #[DataProvider('savedStructureProvider')]
    public function testPreviewMatchesTheBonusesOfTheSavedStructure(string $structureType, string $securityType, array $rigs): void
    {
        $saved = (new IndustryStructureConfig())
            ->setName('Saved structure')
            ->setStructureType($structureType)
            ->setSecurityType($securityType)
            ->setRigs($rigs);

        $preview = $this->preview($structureType, $securityType, $rigs);

        $this->assertSame($saved->getManufacturingMaterialBonus(), $preview->manufacturingMaterialBonus);
        $this->assertSame($saved->getManufacturingTimeBonus(), $preview->manufacturingTimeBonus);
        $this->assertSame($saved->getReactionMaterialBonus(), $preview->reactionMaterialBonus);
        $this->assertSame($saved->getReactionTimeBonus(), $preview->reactionTimeBonus);
    }

    /** @param string[] $rigs */
    private function preview(string $structureType, string $securityType, array $rigs): StructureBonusPreviewResource
    {
        $input = new StructureBonusPreviewInput();
        $input->structureType = $structureType;
        $input->securityType = $securityType;
        $input->rigs = $rigs;

        return (new StructureBonusPreviewProcessor())->process($input, new Post());
    }
}
