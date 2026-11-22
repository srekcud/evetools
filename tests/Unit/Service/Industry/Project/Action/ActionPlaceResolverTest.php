<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Constant\EveConstants;
use App\Entity\CachedStructure;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\SourcingMarket;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\ActionPlaceResolver;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionPlaceResolver::class)]
#[CoversClass(ActionPlace::class)]
#[Group('issue-1300')]
final class ActionPlaceResolverTest extends TestCase
{
    use BuildsActionPlaces;
    use BuildsWorkshopProjects;

    private const int ZYDRINE_TYPE_ID = 39;

    public function testJitaMarketIsTheJitaStationWithItsNameAndItsSolarSystem(): void
    {
        $place = $this->placeResolver()->ofMarket(SourcingMarket::Jita, new User());

        self::assertSame(
            ['station', EveConstants::JITA_STATION_ID, self::JITA_STATION_NAME, EveConstants::JITA_SOLAR_SYSTEM_ID, self::JITA_SYSTEM_NAME, null],
            array_values($place->toArray()),
        );
    }

    public function testStructureMarketIsTheMarketStructureOfTheViewerWithTheSolarSystemOfTheCache(): void
    {
        $cached = (new CachedStructure())->setStructureId(self::MARKET_STRUCTURE_ID)->setName('cached')->setSolarSystemId(self::PERIMETER_SYSTEM_ID);

        $place = $this->placeResolver(cachedStructures: [self::MARKET_STRUCTURE_ID => $cached])->ofMarket(SourcingMarket::Structure, $this->viewerFollowingTheMarketStructure());

        self::assertSame(
            ['station', self::MARKET_STRUCTURE_ID, self::MARKET_STRUCTURE_NAME, self::PERIMETER_SYSTEM_ID, self::PERIMETER_SYSTEM_NAME, null],
            array_values($place->toArray()),
        );
    }

    public function testStructureMarketWhoseSolarSystemIsUnknownToTheCacheKeepsTheStationWithoutSolarSystem(): void
    {
        $unresolved = (new CachedStructure())->setStructureId(self::MARKET_STRUCTURE_ID)->setName('cached')->setSolarSystemId(null);

        foreach ([[], [self::MARKET_STRUCTURE_ID => $unresolved]] as $cachedStructures) {
            $place = $this->placeResolver(cachedStructures: $cachedStructures)->ofMarket(SourcingMarket::Structure, $this->viewerFollowingTheMarketStructure());

            self::assertSame(
                ['station', self::MARKET_STRUCTURE_ID, self::MARKET_STRUCTURE_NAME, null, null, null],
                array_values($place->toArray()),
            );
        }
    }

    public function testStructureMarketWithoutMarketStructureOfTheViewerIsUnknownBecauseTheStructureIsUnset(): void
    {
        $place = $this->placeResolver()->ofMarket(SourcingMarket::Structure, new User());

        self::assertSame(
            ['unknown', null, null, null, null, ActionPlace::UNKNOWN_MARKET_STRUCTURE_UNSET],
            array_values($place->toArray()),
        );
    }

    public function testMarketNotChosenIsUnknownAndNeverAnywhere(): void
    {
        $place = $this->placeResolver()->ofMarket(null, $this->viewerFollowingTheMarketStructure());

        self::assertSame(
            ['unknown', null, null, null, null, ActionPlace::UNKNOWN_MARKET_NOT_CHOSEN],
            array_values($place->toArray()),
        );
    }

    public function testStepIsTheStationOfTheStructureConfigurationPlannedByTheLine(): void
    {
        $azbel = $this->azbelConfig();
        $owner = new User();
        $line = $this->jobLineWithPlannedStructure($azbel->getId()->toRfc4122(), $owner);

        $place = $this->placeResolver([$this->azbelConfig(1_040_000_009_999), $azbel])->ofStep($line, $owner);

        self::assertSame(
            ['station', self::AZBEL_LOCATION_ID, self::AZBEL_NAME, self::PERIMETER_SYSTEM_ID, self::PERIMETER_SYSTEM_NAME, null],
            array_values($place->toArray()),
        );
    }

    public function testStepWithoutPlannedStructureIsUnknownBecauseTheStationIsUnresolved(): void
    {
        $owner = new User();

        $place = $this->placeResolver([$this->azbelConfig()])->ofStep($this->jobLineWithPlannedStructure(null, $owner), $owner);

        self::assertSame(
            ['unknown', null, null, null, null, ActionPlace::UNKNOWN_STATION_UNRESOLVED],
            array_values($place->toArray()),
        );
    }

    public function testStepWhoseConfigurationIsNotAmongTheOwnerConfigurationsIsUnknownBecauseTheStationIsUnresolved(): void
    {
        $owner = new User();
        $line = $this->jobLineWithPlannedStructure($this->azbelConfig()->getId()->toRfc4122(), $owner);

        $place = $this->placeResolver([$this->azbelConfig(1_040_000_009_999)])->ofStep($line, $owner);

        self::assertSame(
            ['unknown', null, null, null, null, ActionPlace::UNKNOWN_STATION_UNRESOLVED],
            array_values($place->toArray()),
        );
    }

    public function testStepWhoseConfigurationHasNoLocationIsUnknownBecauseTheStationIsUnresolved(): void
    {
        $withoutLocation = $this->azbelConfig(null);
        $owner = new User();
        $line = $this->jobLineWithPlannedStructure($withoutLocation->getId()->toRfc4122(), $owner);

        $place = $this->placeResolver([$withoutLocation])->ofStep($line, $owner);

        self::assertSame(
            ['unknown', null, null, null, null, ActionPlace::UNKNOWN_STATION_UNRESOLVED],
            array_values($place->toArray()),
        );
    }

    private function jobLineWithPlannedStructure(?string $plannedStructureId, User $owner): IndustryProjectBomLine
    {
        $project = $this->workshopProject('Zydrine batch', ProjectStatus::InProgress, $owner);

        return $this->jobOf($project, self::ZYDRINE_TYPE_ID, 'Zydrine', 'component', 8, 8, 0)->setPlannedStructureId($plannedStructureId);
    }
}
