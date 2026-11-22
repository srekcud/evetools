<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StructureBonusPreviewResource;
use App\ApiResource\Input\Industry\StructureBonusPreviewInput;
use App\Entity\IndustryStructureConfig;
use App\State\Processor\Industry\StructureBonusPreviewProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Support\StubsSdeStructureBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureBonusPreviewProcessor::class)]
final class StructureBonusPreviewProcessorTest extends TestCase
{
    use StubsSdeStructureBonuses;

    public static function configurationProvider(): iterable
    {
        yield 'Athanor nullsec: no reaction time bonus' => ['athanor', 'nullsec', [self::SDE_COMPOSITE_REACTOR_ME_II], 0.0, 0.0, 2.64, 0.0];
        yield 'Tatara nullsec: 25 % base and 24 % x 1.1 rig' => ['tatara', 'nullsec', [self::SDE_L_SET_REACTOR_EFFICIENCY_II], 0.0, 0.0, 2.64, 44.8];
        yield 'Sotiyo lowsec, Thukker rig x1.9' => ['sotiyo', 'lowsec', [self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT], 3.8, 56.6, 0.0, 0.0];
        yield 'Sotiyo nullsec, Thukker rig x0.1' => ['sotiyo', 'nullsec', [self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT], 0.2, 31.4, 0.0, 0.0];
    }

    #[DataProvider('configurationProvider')]
    #[Group('issue-1331')]
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
        $this->assertSame([], $preview->unrecognisedRigs);
    }

    #[Group('issue-1331')]
    public function testPreviewListsTheRigsMissingFromTheSdeOnceAndKeepsTheOtherBonuses(): void
    {
        $preview = $this->preview('athanor', 'nullsec', [
            self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II,
            self::SDE_COMPOSITE_REACTOR_ME_II,
            self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II,
        ]);

        $this->assertSame([self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II], $preview->unrecognisedRigs);
        $this->assertSame(2.64, $preview->reactionMaterialBonus);
    }

    #[Group('issue-1343')]
    public function testPreviewOfARefineryOfLegacyTypeIsAHullToSpecifyWithUnknownTimeBonuses(): void
    {
        $preview = $this->preview('refinery', 'nullsec', [self::SDE_COMPOSITE_REACTOR_ME_II]);

        $this->assertTrue($preview->hullToSpecify);
        $this->assertNull($preview->manufacturingTimeBonus);
        $this->assertNull($preview->reactionTimeBonus);
        $this->assertSame(2.64, $preview->reactionMaterialBonus);
    }

    #[Group('issue-1343')]
    public function testPreviewOfAKnownHullIsNotAHullToSpecifyAndKeepsNumericTimeBonuses(): void
    {
        $preview = $this->preview('tatara', 'nullsec', [self::SDE_L_SET_REACTOR_EFFICIENCY_II]);

        $this->assertFalse($preview->hullToSpecify);
        $this->assertSame(0.0, $preview->manufacturingTimeBonus);
        $this->assertSame(44.8, $preview->reactionTimeBonus);
    }

    public static function savedStructureProvider(): iterable
    {
        foreach (self::configurationProvider() as $label => [$structureType, $securityType, $rigs]) {
            yield $label => [$structureType, $securityType, $rigs];
        }
    }

    #[DataProvider('savedStructureProvider')]
    public function testPreviewMatchesTheBonusesOfTheSavedStructure(string $structureType, string $securityType, array $rigs): void
    {
        $saved = (new IndustryStructureConfig())
            ->setName('Saved structure')
            ->setStructureType($structureType)
            ->setSecurityType($securityType)
            ->setRigs($rigs);

        $preview = $this->preview($structureType, $securityType, $rigs);
        $resource = (new IndustryResourceMapper($this->sdeStructureBonusSummary()))->structureToResource($saved);

        $this->assertSame($resource->manufacturingMaterialBonus, $preview->manufacturingMaterialBonus);
        $this->assertSame($resource->manufacturingTimeBonus, $preview->manufacturingTimeBonus);
        $this->assertSame($resource->reactionMaterialBonus, $preview->reactionMaterialBonus);
        $this->assertSame($resource->reactionTimeBonus, $preview->reactionTimeBonus);
    }

    private function preview(string $structureType, string $securityType, array $rigs): StructureBonusPreviewResource
    {
        $input = new StructureBonusPreviewInput();
        $input->structureType = $structureType;
        $input->securityType = $securityType;
        $input->rigs = $rigs;

        return (new StructureBonusPreviewProcessor($this->sdeStructureBonusSummary()))->process($input, new Post());
    }
}
