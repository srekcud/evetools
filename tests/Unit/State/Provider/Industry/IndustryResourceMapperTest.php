<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryStructureConfig;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\InventionService;
use App\State\Provider\Industry\IndustryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
class IndustryResourceMapperTest extends TestCase
{
    public function testStructureResourceExposesReactionMaterialBonusWithReactionSecurityMultiplier(): void
    {
        $mapper = new IndustryResourceMapper(
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        );

        $tatara = new IndustryStructureConfig();
        $tatara->setName('Nullsec Tatara');
        $tatara->setStructureType('tatara');
        $tatara->setSecurityType('nullsec');
        $tatara->setRigs(['Standup M-Set Composite Reactor Material Efficiency II']);

        $resource = $mapper->structureToResource($tatara);

        // Issue #72: 2.4% x 1.1 (nullsec reaction multiplier), not x 2.1 (manufacturing)
        $this->assertSame(2.64, $resource->reactionMaterialBonus);
        $this->assertSame(0.0, $resource->manufacturingMaterialBonus);
    }
}
