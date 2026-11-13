<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\User;
use App\Service\Industry\Project\ProjectSellStructures;
use App\Service\Industry\Project\SellStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectSellStructures::class)]
#[Group('issue-50')]
final class ProjectSellStructuresTest extends TestCase
{
    private const int JITA_STRUCTURE_ID = 60003760;
    private const int KEEPSTAR_STRUCTURE_ID = 1042000000001;
    private const int FORTIZAR_STRUCTURE_ID = 1042000000002;

    private ProjectSellStructures $sellStructures;

    protected function setUp(): void
    {
        $this->sellStructures = new ProjectSellStructures();
    }

    public function testThePreferredMarketStructureIsTheDefaultWithItsName(): void
    {
        $pilot = (new User())->setPreferredMarketStructureId(self::JITA_STRUCTURE_ID)->setPreferredMarketStructureName('Jita 4-4');

        self::assertEquals(new SellStructure(self::JITA_STRUCTURE_ID, 'Jita 4-4'), $this->sellStructures->defaultFor($pilot));
    }

    public function testAPreferredMarketStructureWithoutNameIsNamedAfterItsIdentifier(): void
    {
        $pilot = (new User())->setPreferredMarketStructureId(self::JITA_STRUCTURE_ID);

        self::assertEquals(new SellStructure(self::JITA_STRUCTURE_ID, 'Structure 60003760'), $this->sellStructures->defaultFor($pilot));
    }

    #[Group('issue-880')]
    public function testAPilotWithoutPreferredMarketStructureHasTheFirstMarketStructureOfTheListAsDefault(): void
    {
        $pilot = (new User())
            ->addMarketStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A')
            ->addMarketStructure(self::FORTIZAR_STRUCTURE_ID, 'Fortizar B');

        self::assertEquals(new SellStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A'), $this->sellStructures->defaultFor($pilot));
    }

    public function testAPilotWithoutAnyMarketStructureHasNoDefault(): void
    {
        self::assertNull($this->sellStructures->defaultFor(new User()));
    }

    public function testTheSelectableStructuresAreThePreferredOneThenTheMarketStructuresWithoutDuplicate(): void
    {
        $pilot = (new User())
            ->setPreferredMarketStructureId(self::JITA_STRUCTURE_ID)
            ->setPreferredMarketStructureName('Jita 4-4')
            ->addMarketStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A')
            ->addMarketStructure(self::JITA_STRUCTURE_ID, 'Jita 4-4 (listed)')
            ->addMarketStructure(self::FORTIZAR_STRUCTURE_ID, 'Fortizar B');

        self::assertEquals([
            new SellStructure(self::JITA_STRUCTURE_ID, 'Jita 4-4'),
            new SellStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A'),
            new SellStructure(self::FORTIZAR_STRUCTURE_ID, 'Fortizar B'),
        ], $this->sellStructures->selectableBy($pilot));
    }

    public function testAPilotWithoutAnyMarketStructureHasNothingToSelect(): void
    {
        self::assertSame([], $this->sellStructures->selectableBy(new User()));
    }

    public function testChoosingAMarketStructureOfThePilotReturnsItWithItsName(): void
    {
        $pilot = (new User())
            ->setPreferredMarketStructureId(self::JITA_STRUCTURE_ID)
            ->addMarketStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A');

        self::assertEquals(new SellStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A'), $this->sellStructures->choose($pilot, self::KEEPSTAR_STRUCTURE_ID));
        self::assertEquals(new SellStructure(self::JITA_STRUCTURE_ID, 'Structure 60003760'), $this->sellStructures->choose($pilot, self::JITA_STRUCTURE_ID));
    }

    public function testChoosingAStructureThePilotDoesNotHaveIsRefused(): void
    {
        $pilot = (new User())->setPreferredMarketStructureId(self::JITA_STRUCTURE_ID);

        $this->expectException(\InvalidArgumentException::class);

        $this->sellStructures->choose($pilot, 999);
    }
}
