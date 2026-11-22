<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\OwnerStructureLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnerStructureLabels::class)]
#[Group('issue-1343')]
final class OwnerStructureLabelsHullToSpecifyTest extends TestCase
{
    public function testOnlyTheLegacyTypeStructuresOfTheOwnerAreListedWithTheirIdNameAndType(): void
    {
        $legacyComplex = $this->structure('Legacy Complex', 'engineering_complex');
        $raitaru = $this->structure('Jita Raitaru', 'raitaru');
        $legacyRefinery = $this->structure('Legacy Refinery', 'refinery');
        $station = $this->structure('Hek VIII', 'station');
        $tatara = $this->structure('Nullsec Tatara', 'tatara');

        $listed = $this->labelsOf([$legacyComplex, $raitaru, $legacyRefinery, $station, $tatara])->hullToSpecifyOf(new User());

        $this->assertSame(
            [
                ['id' => $legacyComplex->getId()->toRfc4122(), 'name' => 'Legacy Complex', 'structureType' => 'engineering_complex'],
                ['id' => $legacyRefinery->getId()->toRfc4122(), 'name' => 'Legacy Refinery', 'structureType' => 'refinery'],
            ],
            $listed,
        );
    }

    public function testOwnerWithoutLegacyTypeStructureHasNothingToSpecify(): void
    {
        $listed = $this->labelsOf([$this->structure('Jita Raitaru', 'raitaru'), $this->structure('Nullsec Athanor', 'athanor')])->hullToSpecifyOf(new User());

        $this->assertSame([], $listed);
    }

    public function testOwnerWithoutAnyStructureHasNothingToSpecify(): void
    {
        $this->assertSame([], $this->labelsOf([])->hullToSpecifyOf(new User()));
    }

    /**
     * @param list<IndustryStructureConfig> $configurations
     */
    private function labelsOf(array $configurations): OwnerStructureLabels
    {
        $repository = $this->createStub(IndustryStructureConfigRepository::class);
        $repository->method('findByUser')->willReturn($configurations);

        return new OwnerStructureLabels($repository, $this->createStub(MapSolarSystemRepository::class));
    }

    private function structure(string $name, string $structureType): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())->setName($name)->setStructureType($structureType);
    }
}
