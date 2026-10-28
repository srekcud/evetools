<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Service\Industry\FacilityTaxResolver;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FacilityTaxResolver::class)]
final class FacilityTaxResolverTest extends TestCase
{
    use CreatesUserWithId;

    private const string USER_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f90';
    private const int REQUESTED_SOLAR_SYSTEM_ID = 30002510;
    private const int OTHER_SOLAR_SYSTEM_ID = 30000142;

    public function testEngineeringComplexInTheRequestedSystemGivesItsFacilityTaxForManufacturing(): void
    {
        $resolver = $this->resolverOf([ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::REQUESTED_SOLAR_SYSTEM_ID, 0.05)]);

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(5.0, $percent);
    }

    public function testUserWithoutStructureGetsTheNpcStationFacilityTax(): void
    {
        $resolver = $this->resolverOf([]);

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(0.25, $percent);
    }

    public function testAbsentUserGetsTheNpcStationFacilityTaxWithoutReadingAnyStructure(): void
    {
        $structures = new InMemoryUserProductionStructures([
            self::USER_ID => [ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::REQUESTED_SOLAR_SYSTEM_ID, 0.05)],
        ]);

        $percent = (new FacilityTaxResolver($structures, InMemorySystemCostIndices::none()))->percentFor(null, self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(0.25, $percent);
        $this->assertSame([], $structures->requestedUserIds);
    }

    public function testEngineeringComplexAloneGetsTheNpcStationFacilityTaxForReaction(): void
    {
        $resolver = $this->resolverOf([ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::REQUESTED_SOLAR_SYSTEM_ID, 0.05)]);

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Reaction);

        $this->assertSame(0.25, $percent);
    }

    public function testRefineryInTheRequestedSystemGivesItsFacilityTaxForReaction(): void
    {
        $resolver = $this->resolverOf([ProductionStructures::tataraWithFacilityTax('tatara', SecurityClass::NullSec, self::REQUESTED_SOLAR_SYSTEM_ID, 0.02)]);

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Reaction);

        $this->assertSame(2.0, $percent);
    }

    public function testEngineeringComplexOutsideTheRequestedSystemIsStillUsedWhenNothingIsConfiguredInIt(): void
    {
        $resolver = $this->resolverOf([ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::OTHER_SOLAR_SYSTEM_ID, 0.05)]);

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(5.0, $percent);
    }

    public function testMostRecentlyConfiguredStructureOfTheRequestedSystemWinsAtEqualMaterialMultiplier(): void
    {
        $resolver = $this->resolverOf([
            $this->raitaruConfiguredAt('2026-03-01 10:00:00', 0.05),
            $this->raitaruConfiguredAt('2026-03-01 10:00:01', 0.01),
        ]);

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(1.0, $percent);
    }

    public function testRequestedSystemWinsOverAStructureElsewhereWithALowerInstallRateForManufacturing(): void
    {
        $resolver = $this->resolverOf([
            ProductionStructures::raitaruConfiguredWithFacilityTax('elsewhere-tax-0-september', self::OTHER_SOLAR_SYSTEM_ID, '2026-09-01 00:00:00', 0.0),
            ProductionStructures::raitaruConfiguredWithFacilityTax('requested-tax-10-january', self::REQUESTED_SOLAR_SYSTEM_ID, '2026-01-01 00:00:00', 0.10),
        ], new InMemorySystemCostIndices([
            self::REQUESTED_SOLAR_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.09, ActivityKind::Copying->name => 0.09],
            self::OTHER_SOLAR_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.01, ActivityKind::Copying->name => 0.01],
        ]));

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(10.0, $percent);
    }

    public function testMostRecentStructureOfTheRequestedSystemWinsOverALowerInstallRateForManufacturing(): void
    {
        $resolver = $this->resolverOf([
            ProductionStructures::sotiyoConfiguredWithFacilityTax('sotiyo-tax-0-january', self::REQUESTED_SOLAR_SYSTEM_ID, '2026-01-01 00:00:00', 0.0),
            ProductionStructures::raitaruConfiguredWithFacilityTax('raitaru-tax-8-september', self::REQUESTED_SOLAR_SYSTEM_ID, '2026-09-01 00:00:00', 0.08),
        ], new InMemorySystemCostIndices([
            self::REQUESTED_SOLAR_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05, ActivityKind::Copying->name => 0.05],
        ]));

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(8.0, $percent);
    }

    public function testCopyingGetsTheFacilityTaxOfTheStructureWithTheLowestInstallRate(): void
    {
        $resolver = $this->resolverOf([
            ProductionStructures::raitaruConfiguredWithFacilityTax('raitaru-tax-10-september', self::REQUESTED_SOLAR_SYSTEM_ID, '2026-09-01 00:00:00', 0.10),
            ProductionStructures::sotiyoConfiguredWithFacilityTax('sotiyo-tax-3-january', self::REQUESTED_SOLAR_SYSTEM_ID, '2026-01-01 00:00:00', 0.03),
        ], new InMemorySystemCostIndices([
            self::REQUESTED_SOLAR_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.07, ActivityKind::Copying->name => 0.05],
        ]));

        $percent = $resolver->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Copying);

        $this->assertSame(3.0, $percent);
    }

    public function testStructuresAreReadForTheRfc4122IdOfTheUser(): void
    {
        $structures = new InMemoryUserProductionStructures();

        (new FacilityTaxResolver($structures, InMemorySystemCostIndices::none()))->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame([self::USER_ID], $structures->requestedUserIds);
    }

    public function testStructuresOfAnotherUserAreIgnored(): void
    {
        $structures = new InMemoryUserProductionStructures([
            '0192f3a4-0000-7000-8000-000000000001' => [ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::REQUESTED_SOLAR_SYSTEM_ID, 0.05)],
        ]);

        $percent = (new FacilityTaxResolver($structures, InMemorySystemCostIndices::none()))->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(0.25, $percent);
    }

    private function resolverOf(array $structures, ?InMemorySystemCostIndices $costIndices = null): FacilityTaxResolver
    {
        return new FacilityTaxResolver(new InMemoryUserProductionStructures([self::USER_ID => $structures]), $costIndices ?? InMemorySystemCostIndices::none());
    }

    private function raitaruConfiguredAt(string $configuredAt, float $facilityTaxPercent): ProductionStructure
    {
        return new ProductionStructure(
            'raitaru-' . $configuredAt,
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            self::REQUESTED_SOLAR_SYSTEM_ID,
            new \DateTimeImmutable($configuredAt),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            [],
            new Multiplier(ProductionStructures::RAITARU_COST_ROLE_BONUS),
            $facilityTaxPercent,
        );
    }
}
