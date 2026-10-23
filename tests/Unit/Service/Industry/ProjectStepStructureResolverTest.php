<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\ProjectStepStructureResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectStepStructureResolver::class)]
#[Group('issue-133')]
final class ProjectStepStructureResolverTest extends TestCase
{
    private const int JITA_SOLAR_SYSTEM_ID = 30000142;
    private const int AMARR_SOLAR_SYSTEM_ID = 30002187;
    private const int PERIMETER_SOLAR_SYSTEM_ID = 30000144;
    private const int FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID = 30004759;
    private const int FAVORITE_REACTION_SOLAR_SYSTEM_ID = 30001234;
    private const float NPC_STATION_FACILITY_TAX_PERCENT = 0.25;

    private IndustryUserSettingsRepository&Stub $userSettingsRepository;
    private ?IndustryStructureConfig $structureRetainedForTheBonus = null;
    private ProjectStepStructureResolver $resolver;

    protected function setUp(): void
    {
        $this->userSettingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('getStructureBonusForStep')->willReturnCallback(
            fn (IndustryProjectStep $step): array => [
                'structure' => $step->getStructureConfig() ?? $this->structureRetainedForTheBonus,
                'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
                'timeBonus' => 0.0,
                'name' => null,
                'favoriteSystemWithoutSuitableStructure' => false,
            ],
        );
        $this->resolver = new ProjectStepStructureResolver($this->userSettingsRepository, $calculationService);
    }

    public function testStepWithAStructureInASolarSystemUsesThatSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $step = $this->stepOf('manufacturing', $this->structure('raitaru', self::AMARR_SOLAR_SYSTEM_ID, 5.0));

        $this->assertSame(self::AMARR_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
    }

    public function testManufacturingStepWithoutStructureUsesTheFavoriteManufacturingSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());

        $this->assertSame(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOf('manufacturing')));
    }

    public function testStepWhoseStructureHasNoSolarSystemUsesTheFavoriteManufacturingSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());

        $this->assertSame(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOf('manufacturing', new IndustryStructureConfig())));
    }

    public function testReactionStepWithoutStructureUsesTheFavoriteReactionSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());

        $this->assertSame(self::FAVORITE_REACTION_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOf('reaction')));
    }

    public function testStepWithoutStructureNorFavoriteUsesJita(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(new IndustryUserSettings());

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOf('manufacturing')));
    }

    public function testReactionStepWithOnlyAFavoriteManufacturingSolarSystemUsesJita(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(
            (new IndustryUserSettings())->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID),
        );

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOf('reaction')));
    }

    public function testStepWithoutStructureOfAUserWithoutSettingsUsesJita(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOf('manufacturing')));
    }

    public function testStepWithoutAssignedStructureFollowsTheSolarSystemAndFacilityTaxOfTheStructureRetainedForTheBonus(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $this->structureRetainedForTheBonus = $this->structure('azbel', self::AMARR_SOLAR_SYSTEM_ID, 3.0);
        $step = $this->stepOf('manufacturing');

        $this->assertSame(self::AMARR_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(3.0, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithoutAssignedStructureRetainedStructureWithoutSolarSystemUsesTheFavoriteSolarSystemAndItsFacilityTax(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $this->structureRetainedForTheBonus = $this->structure('raitaru', null, 2.0);
        $step = $this->stepOf('manufacturing');

        $this->assertSame(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(2.0, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithoutAnyStructureUsesTheFavoriteSolarSystemAndTheNpcStationFacilityTax(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $step = $this->stepOf('manufacturing');

        $this->assertSame(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(self::NPC_STATION_FACILITY_TAX_PERCENT, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithoutAnyStructureNorFavoriteUsesJitaAndTheNpcStationFacilityTax(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(new IndustryUserSettings());
        $step = $this->stepOf('manufacturing');

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(self::NPC_STATION_FACILITY_TAX_PERCENT, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithAnAssignedStructureUsesItsSolarSystemAndFacilityTax(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $this->structureRetainedForTheBonus = $this->structure('azbel', self::AMARR_SOLAR_SYSTEM_ID, 3.0);
        $step = $this->stepOf('manufacturing', $this->structure('raitaru', self::PERIMETER_SOLAR_SYSTEM_ID, 5.0));

        $this->assertSame(self::PERIMETER_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(5.0, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithoutAssignedStructureRetainedNpcStationUsesItsSolarSystemAndTheNpcStationFacilityTax(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $this->structureRetainedForTheBonus = $this->structure('station', self::JITA_SOLAR_SYSTEM_ID, null);
        $step = $this->stepOf('manufacturing');

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(self::NPC_STATION_FACILITY_TAX_PERCENT, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testReactionStepWithoutAssignedStructureFollowsTheSolarSystemAndFacilityTaxOfTheRetainedRefinery(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $this->structureRetainedForTheBonus = $this->structure('tatara', self::PERIMETER_SOLAR_SYSTEM_ID, 1.0);
        $step = $this->stepOf('reaction');

        $this->assertSame(self::PERIMETER_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(1.0, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithoutAssignedStructureRetainedPlayerStructureWithoutFacilityTaxHasNoFacilityTax(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $this->structureRetainedForTheBonus = $this->structure('raitaru', self::AMARR_SOLAR_SYSTEM_ID, null);

        $this->assertSame(0.0, $this->resolver->facilityTaxPercentOf($this->stepOf('manufacturing')));
    }

    private function settingsWithFavorites(): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID)
            ->setFavoriteReactionSystemId(self::FAVORITE_REACTION_SOLAR_SYSTEM_ID);
    }

    private function structure(string $structureType, ?int $solarSystemId, ?float $facilityTaxPercent): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setStructureType($structureType)
            ->setFacilityTaxPercent($facilityTaxPercent);

        return $solarSystemId === null ? $structure : $structure->setSolarSystemId($solarSystemId);
    }

    private function stepOf(string $activityType, ?IndustryStructureConfig $structureConfig = null): IndustryProjectStep
    {
        $project = (new IndustryProject())->setUser(new User());
        $step = (new IndustryProjectStep())
            ->setActivityType($activityType)
            ->setDepth(0)
            ->setStructureConfig($structureConfig);
        $project->addStep($step);

        return $step;
    }
}
