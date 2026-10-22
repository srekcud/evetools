<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Doctrine;

use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\SecurityClass;
use App\Industry\Infrastructure\Doctrine\ConfiguredProductionStructures;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter of the UserProductionStructures port: an IndustryStructureConfig of the user becomes a ProductionStructure
 * (spec R2, R4, R6, D10). Role bonuses and rig bonuses come from the SDE dogma attributes (SdeStructureBonusReader,
 * SdeRigBonusReader); the rigs are stored by name and target the categories of their name (StructureRigTargets,
 * the table of SeedRigDefinitionsCommand::RIG_CATEGORY_MAPPINGS).
 *
 * - The facility tax is stored as a percent (3.5 = 3.5 %) and given to the core as a fraction; a tax the user never set
 *   is 0 % (D14, 2026-10-22: documented convention, not missing data).
 * - An NPC station is no production structure: the job falls back to the NPC station of the core (R6).
 * - The legacy types engineering_complex / refinery and an unknown security class are refused: no guessed bonus.
 * - A rig without material bonus (time rig) adds nothing; a Thukker rig is two StructureRig: its capital component
 *   bonus (attribute 2653) on basic_capital_component, its generic bonus (2594) on the other categories it targets.
 *
 * Constructor: (IndustryStructureConfigRepository, InvTypeRepository, SdeStructureBonusReader, SdeRigBonusReader).
 * The repositories are stubbed like the database: findBy() filters the configurations on its criteria, findByNames()
 * resolves the rig names, the dogma rows are filtered by typeId and attribute ids. Dogma values: dev database,
 * 2026-10-22 (whole numbers in value_int, others in value_float, as SdeDogmaImporter stores them).
 */
#[CoversClass(ConfiguredProductionStructures::class)]
final class ConfiguredProductionStructuresTest extends TestCase
{
    private const string USER_ID = '0199f0a8-3b7e-7c1a-9d2e-4f5a6b7c8d9e';
    private const string OTHER_USER_ID = '0199f0a8-3b7e-7c1a-9d2e-4f5a6b7c8d9f';
    private const int SOLAR_SYSTEM_ID = 30002187;

    private const string AMMUNITION_ME_II = 'Standup M-Set Ammunition Manufacturing Material Efficiency II';
    private const string EQUIPMENT_TE_I = 'Standup M-Set Equipment Manufacturing Time Efficiency I';
    private const string COMPOSITE_REACTOR_ME_II = 'Standup M-Set Composite Reactor Material Efficiency II';
    private const string COMPOSITE_REACTOR_TE_I = 'Standup M-Set Composite Reactor Time Efficiency I';
    private const string M_SET_THUKKER_BASIC_CAPITAL_COMPONENT = 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency';
    private const string XL_SET_THUKKER_STRUCTURE_AND_COMPONENT = 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency';

    /** typeId by rig name (sde_inv_types). */
    private const array RIG_TYPE_IDS = [
        self::AMMUNITION_ME_II => 37159,
        self::EQUIPMENT_TE_I => 37160,
        self::COMPOSITE_REACTOR_ME_II => 46487,
        self::COMPOSITE_REACTOR_TE_I => 46484,
        self::M_SET_THUKKER_BASIC_CAPITAL_COMPONENT => 45544,
        self::XL_SET_THUKKER_STRUCTURE_AND_COMPONENT => 45548,
    ];

    /**
     * typeId => [attributeId => value]: 2600 strEngMatBonus, 2601 strEngCostBonus, 2355 / 2356 / 2357 security
     * modifiers, 2593 / 2594 manufacturing time / material bonus, 2653 Thukker material bonus, 2713 / 2714 reaction
     * time / material bonus. The refineries (Athanor 35835, Tatara 35836) carry no role bonus.
     */
    private const array DOGMA_ATTRIBUTES = [
        35825 => [2600 => 0.99, 2601 => 0.97],
        35826 => [2600 => 0.99, 2601 => 0.96],
        35827 => [2600 => 0.99, 2601 => 0.95],
        35835 => [],
        35836 => [],
        37159 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => 0, 2594 => -2.4],
        37160 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => -20, 2594 => 0],
        46487 => [2356 => 1, 2357 => 1.1, 2714 => -2.4],
        46484 => [2356 => 1, 2357 => 1.1, 2713 => -20],
        45544 => [2355 => 0.1, 2356 => 1.9, 2357 => 0.1, 2593 => 0, 2653 => -3.7],
        45548 => [2355 => 0.1, 2356 => 1.9, 2357 => 0.1, 2593 => -20, 2594 => -2, 2653 => -3.7],
    ];

    private const float FLOAT_TOLERANCE = 1e-12;

    /** @var list<IndustryStructureConfig> rows of industry_structure_configs */
    private array $configurations = [];

    /**
     * @param list<float> $expectedRoleBonuses material role bonus, cost role bonus
     */
    #[DataProvider('structureTypeProvider')]
    public function testStructureTypeGivesItsFamilyAndItsSdeRoleBonuses(string $structureType, StructureType $expectedType, array $expectedRoleBonuses): void
    {
        $this->configure($structureType, 'nullsec');

        $structure = $this->onlyStructureOf(self::USER_ID);

        $this->assertSame($expectedType, $structure->type);
        $this->assertSame($expectedRoleBonuses[0], $structure->manufacturingMaterialRoleBonus->value);
        $this->assertSame($expectedRoleBonuses[1], $structure->costRoleBonus->value);
    }

    /**
     * @return iterable<string, array{string, StructureType, list<float>}>
     */
    public static function structureTypeProvider(): iterable
    {
        yield 'Raitaru 35825' => ['raitaru', StructureType::EngineeringComplex, [0.99, 0.97]];
        yield 'Azbel 35826' => ['azbel', StructureType::EngineeringComplex, [0.99, 0.96]];
        yield 'Sotiyo 35827' => ['sotiyo', StructureType::EngineeringComplex, [0.99, 0.95]];
        yield 'Athanor 35835, no role bonus in the SDE' => ['athanor', StructureType::Refinery, [1.0, 1.0]];
        yield 'Tatara 35836, no role bonus in the SDE' => ['tatara', StructureType::Refinery, [1.0, 1.0]];
    }

    #[DataProvider('securityProvider')]
    public function testSecurityTypeGivesTheSecurityClass(string $securityType, SecurityClass $expectedSecurity): void
    {
        $this->configure('raitaru', $securityType);

        $this->assertSame($expectedSecurity, $this->onlyStructureOf(self::USER_ID)->security);
    }

    /**
     * @return iterable<string, array{string, SecurityClass}>
     */
    public static function securityProvider(): iterable
    {
        yield 'highsec' => ['highsec', SecurityClass::HighSec];
        yield 'lowsec' => ['lowsec', SecurityClass::LowSec];
        yield 'nullsec' => ['nullsec', SecurityClass::NullSec];
    }

    public function testStructureKeepsTheIdTheSystemAndTheCreationDateOfItsConfiguration(): void
    {
        $configuration = $this->configure('raitaru', 'nullsec', createdAt: '2026-03-14 09:26:53');

        $structure = $this->onlyStructureOf(self::USER_ID);

        $this->assertSame($configuration->getId()->toRfc4122(), $structure->id);
        $this->assertSame(self::SOLAR_SYSTEM_ID, $structure->solarSystemId);
        $this->assertEquals(new \DateTimeImmutable('2026-03-14 09:26:53'), $structure->configuredAt);
    }

    public function testFacilityTaxStoredAsAPercentIsGivenAsAFraction(): void
    {
        $this->configure('raitaru', 'nullsec', facilityTaxPercent: 3.5);

        $this->assertEqualsWithDelta(0.035, $this->onlyStructureOf(self::USER_ID)->facilityTaxRate, self::FLOAT_TOLERANCE);
    }

    public function testFacilityTaxNeverSetIsZeroPercentD14(): void
    {
        // D14 (2026-10-22): the structure form does not expose the tax yet; not set = 0 %, documented convention.
        $this->configure('raitaru', 'nullsec', facilityTaxPercent: null);

        $this->assertSame(0.0, $this->onlyStructureOf(self::USER_ID)->facilityTaxRate);
    }

    public function testNpcStationIsNoProductionStructure(): void
    {
        $this->configure('station', 'highsec');
        $raitaru = $this->configure('raitaru', 'highsec');

        $structures = $this->structures()->configuredBy(self::USER_ID);

        $this->assertCount(1, $structures);
        $this->assertSame($raitaru->getId()->toRfc4122(), $structures[0]->id);
    }

    #[DataProvider('legacyStructureTypeProvider')]
    public function testLegacyStructureTypeIsRefusedRatherThanGivenAGuessedBonus(string $legacyStructureType): void
    {
        $this->configure($legacyStructureType, 'nullsec');

        $this->expectException(\UnexpectedValueException::class);

        $this->structures()->configuredBy(self::USER_ID);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyStructureTypeProvider(): iterable
    {
        yield 'engineering_complex' => ['engineering_complex'];
        yield 'refinery' => ['refinery'];
    }

    public function testUnknownSecurityTypeIsRefused(): void
    {
        $this->configure('raitaru', 'wormhole');

        $this->expectException(\UnexpectedValueException::class);

        $this->structures()->configuredBy(self::USER_ID);
    }

    public function testManufacturingRigReducesTheMaterialsOfTheCategoriesOfItsNameOnly(): void
    {
        $this->configure('raitaru', 'nullsec', rigs: [self::AMMUNITION_ME_II]);

        $structure = $this->onlyStructureOf(self::USER_ID);

        // 0.99 × (1 − 2.4 × 2.1 / 100) = 0.940104; equipment is not targeted: role bonus only.
        $this->assertEqualsWithDelta(0.940104, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'ammunition')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.99, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'equipment')->value, self::FLOAT_TOLERANCE);
    }

    public function testRefineryRigReducesTheMaterialsOfItsReactions(): void
    {
        $this->configure('tatara', 'nullsec', rigs: [self::COMPOSITE_REACTOR_ME_II]);

        $structure = $this->onlyStructureOf(self::USER_ID);

        // 1 − 2.4 × 1.1 / 100 = 0.9736, no role bonus.
        $this->assertEqualsWithDelta(0.9736, $structure->materialMultiplierFor(ActivityKind::Reaction, 'composite_reaction')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $structure->materialMultiplierFor(ActivityKind::Reaction, 'hybrid_reaction')->value, self::FLOAT_TOLERANCE);
    }

    public function testTimeRigGivesNoMaterialBonus(): void
    {
        // Equipment TE I carries a material bonus of 0; Composite Reactor TE I carries none at all.
        $this->configure('raitaru', 'nullsec', rigs: [self::EQUIPMENT_TE_I]);
        $this->configure('tatara', 'nullsec', rigs: [self::COMPOSITE_REACTOR_TE_I, self::COMPOSITE_REACTOR_ME_II]);

        $structures = $this->structures()->configuredBy(self::USER_ID);

        $this->assertCount(2, $structures);
        $raitaru = self::structureOfType($structures, StructureType::EngineeringComplex);
        $tatara = self::structureOfType($structures, StructureType::Refinery);
        $this->assertEqualsWithDelta(0.99, $raitaru->materialMultiplierFor(ActivityKind::Manufacturing, 'equipment')->value, self::FLOAT_TOLERANCE);
        $this->assertCount(1, $tatara->rigs);
        $this->assertEqualsWithDelta(0.9736, $tatara->materialMultiplierFor(ActivityKind::Reaction, 'composite_reaction')->value, self::FLOAT_TOLERANCE);
    }

    public function testThukkerRigIsItsCapitalComponentBonusOnBasicCapitalComponentsAndItsGenericBonusElsewhere(): void
    {
        // XL-Set Thukker: 2653 = −3.7 on basic_capital_component, 2594 = −2 on structure, structure_component and
        // advanced_component; lowsec modifier 1.9.
        $this->configure('sotiyo', 'lowsec', rigs: [self::XL_SET_THUKKER_STRUCTURE_AND_COMPONENT]);

        $structure = $this->onlyStructureOf(self::USER_ID);

        $this->assertCount(2, $structure->rigs);
        // 0.99 × (1 − 3.7 × 1.9 / 100) = 0.920403, never both bonuses.
        $this->assertEqualsWithDelta(0.920403, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'basic_capital_component')->value, self::FLOAT_TOLERANCE);
        // 0.99 × (1 − 2 × 1.9 / 100) = 0.95238.
        $this->assertEqualsWithDelta(0.95238, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'structure')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.95238, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'structure_component')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.95238, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'advanced_component')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.99, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'capital_ship')->value, self::FLOAT_TOLERANCE);
    }

    public function testThukkerRigWithoutGenericBonusOnlyReducesBasicCapitalComponents(): void
    {
        // M-Set Thukker Basic Capital Component: 2653 = −3.7, no 2594.
        $this->configure('raitaru', 'lowsec', rigs: [self::M_SET_THUKKER_BASIC_CAPITAL_COMPONENT]);

        $structure = $this->onlyStructureOf(self::USER_ID);

        $this->assertCount(1, $structure->rigs);
        $this->assertEqualsWithDelta(0.920403, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'basic_capital_component')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.99, $structure->materialMultiplierFor(ActivityKind::Manufacturing, 'advanced_component')->value, self::FLOAT_TOLERANCE);
    }

    public function testOnlyTheConfigurationsOfTheUserThatAreNotDeletedAreRead(): void
    {
        $kept = $this->configure('raitaru', 'nullsec');
        $this->configure('azbel', 'nullsec')->setIsDeleted(true);
        $this->configure('sotiyo', 'nullsec', userId: self::OTHER_USER_ID);

        $structures = $this->structures()->configuredBy(self::USER_ID);

        $this->assertSame([$kept->getId()->toRfc4122()], array_map(static fn (ProductionStructure $structure): string => $structure->id, $structures));
    }

    public function testUserWithoutConfigurationHasNoStructure(): void
    {
        $this->configure('raitaru', 'nullsec', userId: self::OTHER_USER_ID);

        $this->assertSame([], $this->structures()->configuredBy(self::USER_ID));
    }

    /**
     * @param list<string> $rigs rig names, as stored by the structure form
     */
    private function configure(
        string $structureType,
        string $securityType,
        array $rigs = [],
        ?float $facilityTaxPercent = null,
        string $createdAt = '2026-01-01 00:00:00',
        string $userId = self::USER_ID,
    ): IndustryStructureConfig {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));

        $configuration = (new IndustryStructureConfig())
            ->setUser($user)
            ->setName("{$structureType} {$securityType}")
            ->setStructureType($structureType)
            ->setSecurityType($securityType)
            ->setSolarSystemId(self::SOLAR_SYSTEM_ID)
            ->setRigs($rigs)
            ->setFacilityTaxPercent($facilityTaxPercent);
        (new \ReflectionProperty(IndustryStructureConfig::class, 'createdAt'))->setValue($configuration, new \DateTimeImmutable($createdAt));
        $this->configurations[] = $configuration;

        return $configuration;
    }

    private function onlyStructureOf(string $userId): ProductionStructure
    {
        $structures = $this->structures()->configuredBy($userId);
        $this->assertCount(1, $structures);

        return $structures[0];
    }

    /**
     * @param list<ProductionStructure> $structures
     */
    private static function structureOfType(array $structures, StructureType $type): ProductionStructure
    {
        $ofType = array_values(array_filter($structures, static fn (ProductionStructure $structure): bool => $type === $structure->type));
        self::assertCount(1, $ofType);

        return $ofType[0];
    }

    private function structures(): ConfiguredProductionStructures
    {
        $dogmaAttributeValues = new DogmaAttributeValues($this->dogmaRepository());

        return new ConfiguredProductionStructures(
            $this->configurationRepository(),
            $this->invTypeRepository(),
            new SdeStructureBonusReader($dogmaAttributeValues),
            new SdeRigBonusReader($dogmaAttributeValues),
        );
    }

    private function configurationRepository(): IndustryStructureConfigRepository
    {
        $repository = $this->createStub(IndustryStructureConfigRepository::class);
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->configurations,
                static fn (IndustryStructureConfig $configuration): bool => self::meetsCriteria($configuration, $criteria),
            )),
        );

        return $repository;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private static function meetsCriteria(IndustryStructureConfig $configuration, array $criteria): bool
    {
        foreach ($criteria as $field => $value) {
            $matches = match ($field) {
                'user' => $configuration->getUser()->getId()?->toRfc4122() === match (true) {
                    $value instanceof User => $value->getId()?->toRfc4122(),
                    $value instanceof Uuid => $value->toRfc4122(),
                    default => (string) $value,
                },
                'isDeleted' => $configuration->isDeleted() === $value,
                default => throw new \LogicException("The stubbed repository does not filter on {$field}."),
            };
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    private function invTypeRepository(): InvTypeRepository
    {
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByNames')->willReturnCallback(static function (array $names): array {
            $resolved = [];
            foreach ($names as $name) {
                if (\array_key_exists($name, self::RIG_TYPE_IDS)) {
                    $resolved[$name] = (new InvType())
                        ->setTypeId(self::RIG_TYPE_IDS[$name])
                        ->setTypeName($name)
                        ->setGroup(new InvGroup());
                }
            }

            return $resolved;
        });

        return $repository;
    }

    private function dogmaRepository(): DgmTypeAttributeRepository
    {
        $repository = $this->createStub(DgmTypeAttributeRepository::class);
        $repository->method('findBy')->willReturnCallback(static function (array $criteria): array {
            $rows = [];
            foreach (self::DOGMA_ATTRIBUTES[$criteria['typeId']] ?? [] as $attributeId => $value) {
                if (!\in_array($attributeId, (array) $criteria['attributeId'], true)) {
                    continue;
                }
                $row = (new DgmTypeAttribute())->setTypeId($criteria['typeId'])->setAttributeId($attributeId);
                $rows[] = \is_int($value) ? $row->setValueInt($value) : $row->setValueFloat($value);
            }

            return $rows;
        });

        return $repository;
    }
}
