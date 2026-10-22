<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Service\Industry\FacilityTaxRateResolver;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FacilityTaxRateResolver::class)]
final class FacilityTaxRateResolverTest extends TestCase
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

        $percent = (new FacilityTaxRateResolver($structures))->percentFor(null, self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

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

    public function testStructuresAreReadForTheRfc4122IdOfTheUser(): void
    {
        $structures = new InMemoryUserProductionStructures();

        (new FacilityTaxRateResolver($structures))->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame([self::USER_ID], $structures->requestedUserIds);
    }

    public function testStructuresOfAnotherUserAreIgnored(): void
    {
        $structures = new InMemoryUserProductionStructures([
            '0192f3a4-0000-7000-8000-000000000001' => [ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::REQUESTED_SOLAR_SYSTEM_ID, 0.05)],
        ]);

        $percent = (new FacilityTaxRateResolver($structures))->percentFor($this->userWithId(self::USER_ID), self::REQUESTED_SOLAR_SYSTEM_ID, ActivityKind::Manufacturing);

        $this->assertSame(0.25, $percent);
    }

    private function resolverOf(array $structures): FacilityTaxRateResolver
    {
        return new FacilityTaxRateResolver(new InMemoryUserProductionStructures([self::USER_ID => $structures]));
    }

    private function raitaruConfiguredAt(string $configuredAt, float $facilityTaxRate): ProductionStructure
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
            $facilityTaxRate,
        );
    }
}
