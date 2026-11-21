<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Doctrine;

use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\DgmTypeEffect;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Industry\Infrastructure\Doctrine\ConfiguredProductionStructures;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\DgmTypeEffectRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ConfiguredProductionStructures::class)]
#[CoversClass(DogmaAttributeValues::class)]
#[CoversClass(SdeRigBonusReader::class)]
#[Group('issue-1136')]
final class ConfiguredProductionStructuresReadCostTest extends TestCase
{
    private const string USER_ID = '0199f0a8-3b7e-7c1a-9d2e-4f5a6b7c8d9e';
    private const string OTHER_USER_ID = '0199f0a8-3b7e-7c1a-9d2e-4f5a6b7c8d9f';
    private const int SOLAR_SYSTEM_ID = 30002187;
    private const int FEW_STRUCTURES = 3;
    private const int MANY_STRUCTURES = 60;
    private const int RAITARU_TYPE_ID = 35825;
    private const int ATTRIBUTE_ROLE_MATERIAL_BONUS = 2600;
    private const int ATTRIBUTE_ROLE_COST_BONUS = 2601;
    private const int ATTRIBUTE_ROLE_TIME_BONUS = 2602;
    private const int ATTRIBUTE_HI_SEC_MODIFIER = 2355;
    private const int ATTRIBUTE_LOW_SEC_MODIFIER = 2356;
    private const int ATTRIBUTE_NULL_SEC_MODIFIER = 2357;
    private const int ATTRIBUTE_RIG_TIME_BONUS = 2593;
    private const int ATTRIBUTE_RIG_MATERIAL_BONUS = 2594;
    private const int ATTRIBUTE_RIG_COST_BONUS = 2595;

    private const array RIG_TYPE_IDS = [
        'Standup M-Set Ammunition Manufacturing Material Efficiency II' => 37159,
        'Standup M-Set Equipment Manufacturing Time Efficiency I' => 37160,
    ];

    private const array DOGMA_ATTRIBUTES = [
        self::RAITARU_TYPE_ID => [self::ATTRIBUTE_ROLE_MATERIAL_BONUS => 0.99, self::ATTRIBUTE_ROLE_COST_BONUS => 0.97, self::ATTRIBUTE_ROLE_TIME_BONUS => 0.85],
        37159 => [self::ATTRIBUTE_HI_SEC_MODIFIER => 1, self::ATTRIBUTE_LOW_SEC_MODIFIER => 1.9, self::ATTRIBUTE_NULL_SEC_MODIFIER => 2.1, self::ATTRIBUTE_RIG_TIME_BONUS => 0, self::ATTRIBUTE_RIG_MATERIAL_BONUS => -2.4, self::ATTRIBUTE_RIG_COST_BONUS => 0],
        37160 => [self::ATTRIBUTE_HI_SEC_MODIFIER => 1, self::ATTRIBUTE_LOW_SEC_MODIFIER => 1.9, self::ATTRIBUTE_NULL_SEC_MODIFIER => 2.1, self::ATTRIBUTE_RIG_TIME_BONUS => -20, self::ATTRIBUTE_RIG_MATERIAL_BONUS => 0, self::ATTRIBUTE_RIG_COST_BONUS => 0],
    ];

    private const array DOGMA_EFFECTS = [
        37159 => [2663, 6808, 6842],
        37160 => [2663, 6806, 6842],
    ];

    /** @var list<IndustryStructureConfig> */
    private array $configurations = [];

    private int $configurationReads = 0;
    private int $rigTypeLookups = 0;
    private int $dogmaAttributeReads = 0;
    private int $dogmaEffectReads = 0;

    public function testTheSdeIsReadTheSameNumberOfTimesForThreeOrSixtyStructuresSharingTheirRigs(): void
    {
        $this->configureStructures(self::FEW_STRUCTURES);
        $this->structures()->configuredBy(self::USER_ID);
        $readsForFewStructures = [$this->rigTypeLookups, $this->dogmaAttributeReads, $this->dogmaEffectReads];

        $this->resetCounters();
        $this->configurations = [];
        $this->configureStructures(self::MANY_STRUCTURES);
        $this->structures()->configuredBy(self::USER_ID);

        $this->assertSame($readsForFewStructures, [$this->rigTypeLookups, $this->dogmaAttributeReads, $this->dogmaEffectReads]);
    }

    public function testTheRigTypesOfAllTheStructuresAreResolvedWithOneLookup(): void
    {
        $this->configureStructures(self::MANY_STRUCTURES);

        $this->structures()->configuredBy(self::USER_ID);

        $this->assertSame(1, $this->rigTypeLookups);
    }

    public function testTheStructuresAreBuiltWithTheirRigBonusesWhenTheReadsAreShared(): void
    {
        $this->configureStructures(self::FEW_STRUCTURES);

        $structures = $this->structures()->configuredBy(self::USER_ID);

        $this->assertCount(self::FEW_STRUCTURES, $structures);
        foreach ($structures as $structure) {
            $this->assertCount(2, $structure->rigs);
            $this->assertSame(0.99, $structure->manufacturingMaterialRoleBonus->value);
            $this->assertSame(0.97, $structure->costRoleBonus->value);
        }
    }

    public function testTheConfigurationsOfAUserAreReadOnceWhateverTheNumberOfCalls(): void
    {
        $this->configureStructures(self::FEW_STRUCTURES);
        $structures = $this->structures();

        $first = $structures->configuredBy(self::USER_ID);
        $second = $structures->configuredBy(self::USER_ID);
        $third = $structures->configuredBy(self::USER_ID);

        $this->assertSame(1, $this->configurationReads);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function testTheStructuresOfTwoUsersAreNotMixedUp(): void
    {
        $this->configureStructures(self::FEW_STRUCTURES);
        $this->configureStructures(1, userId: self::OTHER_USER_ID);
        $structures = $this->structures();

        $this->assertCount(self::FEW_STRUCTURES, $structures->configuredBy(self::USER_ID));
        $this->assertCount(1, $structures->configuredBy(self::OTHER_USER_ID));
        $this->assertCount(self::FEW_STRUCTURES, $structures->configuredBy(self::USER_ID));
    }

    public function testResetForgetsTheStructuresSoTheNextRequestReadsTheConfigurationsAgain(): void
    {
        $this->configureStructures(self::FEW_STRUCTURES);
        $structures = $this->structures();
        $structures->configuredBy(self::USER_ID);

        $structures->reset();
        $this->configureStructures(1);
        $afterReset = $structures->configuredBy(self::USER_ID);

        $this->assertSame(2, $this->configurationReads);
        $this->assertCount(self::FEW_STRUCTURES + 1, $afterReset);
    }

    public function testTheDogmaAttributesOfATypeAreReadOnceForTheSameAttributes(): void
    {
        $values = new DogmaAttributeValues($this->dogmaRepository());

        $first = $values->of(self::RAITARU_TYPE_ID, [self::ATTRIBUTE_ROLE_COST_BONUS]);
        $second = $values->of(self::RAITARU_TYPE_ID, [self::ATTRIBUTE_ROLE_COST_BONUS]);

        $this->assertSame(1, $this->dogmaAttributeReads);
        $this->assertSame([self::ATTRIBUTE_ROLE_COST_BONUS => 0.97], $first);
        $this->assertSame($first, $second);
    }

    public function testTheDogmaAttributesOfATypeAreReadAgainForOtherAttributes(): void
    {
        $values = new DogmaAttributeValues($this->dogmaRepository());

        $cost = $values->of(self::RAITARU_TYPE_ID, [self::ATTRIBUTE_ROLE_COST_BONUS]);
        $material = $values->of(self::RAITARU_TYPE_ID, [self::ATTRIBUTE_ROLE_MATERIAL_BONUS]);

        $this->assertSame(2, $this->dogmaAttributeReads);
        $this->assertSame([self::ATTRIBUTE_ROLE_COST_BONUS => 0.97], $cost);
        $this->assertSame([self::ATTRIBUTE_ROLE_MATERIAL_BONUS => 0.99], $material);
    }

    public function testResetMakesTheDogmaAttributesBeReadAgain(): void
    {
        $values = new DogmaAttributeValues($this->dogmaRepository());
        $values->of(self::RAITARU_TYPE_ID, [self::ATTRIBUTE_ROLE_COST_BONUS]);

        $values->reset();
        $values->of(self::RAITARU_TYPE_ID, [self::ATTRIBUTE_ROLE_COST_BONUS]);

        $this->assertSame(2, $this->dogmaAttributeReads);
    }

    public function testTheEffectsOfARigAreReadOnceWhateverTheNumberOfScienceRigReads(): void
    {
        $reader = new SdeRigBonusReader(new DogmaAttributeValues($this->dogmaRepository()), $this->effectRepository());

        $reader->scienceRigsOf(37159);
        $reader->scienceRigsOf(37159);
        $reader->materialBonusOf(37159, \App\Industry\Domain\ActivityKind::Manufacturing);

        $this->assertSame(1, $this->dogmaEffectReads);
    }

    public function testResetMakesTheRigEffectsBeReadAgain(): void
    {
        $reader = new SdeRigBonusReader(new DogmaAttributeValues($this->dogmaRepository()), $this->effectRepository());
        $reader->scienceRigsOf(37159);

        $reader->reset();
        $reader->scienceRigsOf(37159);

        $this->assertSame(2, $this->dogmaEffectReads);
    }

    private function resetCounters(): void
    {
        $this->configurationReads = 0;
        $this->rigTypeLookups = 0;
        $this->dogmaAttributeReads = 0;
        $this->dogmaEffectReads = 0;
    }

    private function configureStructures(int $count, string $userId = self::USER_ID): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));
        for ($index = 0; $index < $count; ++$index) {
            $this->configurations[] = (new IndustryStructureConfig())
                ->setUser($user)
                ->setName("Raitaru {$index}")
                ->setStructureType('raitaru')
                ->setSecurityType('nullsec')
                ->setSolarSystemId(self::SOLAR_SYSTEM_ID)
                ->setRigs(array_keys(self::RIG_TYPE_IDS));
        }
    }

    private function structures(): ConfiguredProductionStructures
    {
        $dogmaAttributeValues = new DogmaAttributeValues($this->dogmaRepository());

        return new ConfiguredProductionStructures(
            $this->configurationRepository(),
            $this->invTypeRepository(),
            new SdeStructureBonusReader($dogmaAttributeValues),
            new SdeRigBonusReader($dogmaAttributeValues, $this->effectRepository()),
        );
    }

    private function configurationRepository(): IndustryStructureConfigRepository
    {
        $repository = $this->createStub(IndustryStructureConfigRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            ++$this->configurationReads;

            return array_values(array_filter(
                $this->configurations,
                static fn (IndustryStructureConfig $configuration): bool => $configuration->getUser()->getId()?->toRfc4122() === (string) $criteria['user'] && !$configuration->isDeleted(),
            ));
        });

        return $repository;
    }

    private function invTypeRepository(): InvTypeRepository
    {
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByNames')->willReturnCallback(function (array $names): array {
            ++$this->rigTypeLookups;
            $resolved = [];
            foreach ($names as $name) {
                if (\array_key_exists($name, self::RIG_TYPE_IDS)) {
                    $resolved[$name] = (new InvType())->setTypeId(self::RIG_TYPE_IDS[$name])->setTypeName($name)->setGroup(new InvGroup());
                }
            }

            return $resolved;
        });

        return $repository;
    }

    private function effectRepository(): DgmTypeEffectRepository
    {
        $repository = $this->createStub(DgmTypeEffectRepository::class);
        $repository->method('findByTypeId')->willReturnCallback(function (int $typeId): array {
            ++$this->dogmaEffectReads;

            return array_map(
                static fn (int $effectId): DgmTypeEffect => (new DgmTypeEffect())->setTypeId($typeId)->setEffectId($effectId)->setIsDefault(false),
                self::DOGMA_EFFECTS[$typeId] ?? [],
            );
        });

        return $repository;
    }

    private function dogmaRepository(): DgmTypeAttributeRepository
    {
        $repository = $this->createStub(DgmTypeAttributeRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            ++$this->dogmaAttributeReads;
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
