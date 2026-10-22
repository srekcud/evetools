<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Sde;

use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;
use App\Industry\Infrastructure\Sde\SdeDecryptors;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Adapter of the Decryptors port (spec R8): the types of the Decryptors group (1304) with their modifiers read from
 * the SDE dogma attributes, sorted by typeId. Replaces the hard-coded table InventionService::DECRYPTORS (#70).
 * A missing attribute is an incomplete SDE: an exception, never a 0.
 *
 * Constructor: (InvTypeRepository, DogmaAttributeValues). The repositories are stubbed at findBy() like the
 * database: types filtered by group, dogma rows filtered by typeId and attribute ids. The importer stores a whole
 * number in value_int and any other number in value_float (SdeDogmaImporter): the fixture keeps that split.
 * Values: sde_dgm_type_attributes of the dev database on 2026-10-22.
 */
#[CoversClass(SdeDecryptors::class)]
final class SdeDecryptorsTest extends TestCase
{
    private const int DECRYPTOR_GROUP_ID = 1304;
    private const int MINERAL_GROUP_ID = 18;
    private const int TRITANIUM = 34;
    private const int PARITY_DECRYPTOR = 34204;

    private const int ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER = 1112;
    private const int ATTRIBUTE_INVENTION_ME_MODIFIER = 1113;
    private const int ATTRIBUTE_INVENTION_TE_MODIFIER = 1114;
    private const int ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER = 1124;

    /**
     * typeId => [name, probability multiplier (1112), runs (1124), ME (1113), TE (1114)].
     */
    private const array SDE_DECRYPTORS = [
        34201 => ['Accelerant Decryptor', 1.2, 1, 2, 10],
        34202 => ['Attainment Decryptor', 1.8, 4, -1, 4],
        34203 => ['Augmentation Decryptor', 0.6, 9, -2, 2],
        34204 => ['Parity Decryptor', 1.5, 3, 1, -2],
        34205 => ['Process Decryptor', 1.1, 0, 3, 6],
        34206 => ['Symmetry Decryptor', 1.0, 2, 1, 8],
        34207 => ['Optimized Attainment Decryptor', 1.9, 2, 1, -2],
        34208 => ['Optimized Augmentation Decryptor', 0.9, 7, 2, 0],
    ];

    /** @var list<DgmTypeAttribute> */
    private array $dogmaRows = [];

    protected function setUp(): void
    {
        foreach (self::SDE_DECRYPTORS as $typeId => [, $probabilityMultiplier, $runs, $materialEfficiency, $timeEfficiency]) {
            $this->dogmaRows[] = self::dogmaRow($typeId, self::ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER, $probabilityMultiplier);
            $this->dogmaRows[] = self::dogmaRow($typeId, self::ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER, $runs);
            $this->dogmaRows[] = self::dogmaRow($typeId, self::ATTRIBUTE_INVENTION_ME_MODIFIER, $materialEfficiency);
            $this->dogmaRows[] = self::dogmaRow($typeId, self::ATTRIBUTE_INVENTION_TE_MODIFIER, $timeEfficiency);
        }
    }

    public function testAllAreTheEightDecryptorsOfTheSdeWithTheirModifiersSortedByTypeId(): void
    {
        $decryptors = $this->decryptors()->all();

        $this->assertEquals([
            new Decryptor(34201, new Multiplier(1.2), 1, 2, 10),
            new Decryptor(34202, new Multiplier(1.8), 4, -1, 4),
            new Decryptor(34203, new Multiplier(0.6), 9, -2, 2),
            new Decryptor(34204, new Multiplier(1.5), 3, 1, -2),
            new Decryptor(34205, new Multiplier(1.1), 0, 3, 6),
            new Decryptor(34206, new Multiplier(1.0), 2, 1, 8),
            new Decryptor(34207, new Multiplier(1.9), 2, 1, -2),
            new Decryptor(34208, new Multiplier(0.9), 7, 2, 0),
        ], $decryptors);
    }

    public function testDecryptorWithoutItsRunModifierIsAnIncompleteSdeNeverZeroRuns(): void
    {
        $this->dogmaRows = array_values(array_filter(
            $this->dogmaRows,
            static fn (DgmTypeAttribute $row): bool => !(self::PARITY_DECRYPTOR === $row->getTypeId()
                && self::ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER === $row->getAttributeId()),
        ));

        $this->expectException(\UnexpectedValueException::class);

        $this->decryptors()->all();
    }

    public function testDecryptorWithoutItsProbabilityMultiplierIsAnIncompleteSde(): void
    {
        $this->dogmaRows = array_values(array_filter(
            $this->dogmaRows,
            static fn (DgmTypeAttribute $row): bool => !(self::PARITY_DECRYPTOR === $row->getTypeId()
                && self::ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER === $row->getAttributeId()),
        ));

        $this->expectException(\UnexpectedValueException::class);

        $this->decryptors()->all();
    }

    private function decryptors(): SdeDecryptors
    {
        return new SdeDecryptors($this->invTypeRepository(), new DogmaAttributeValues($this->dogmaRepository()));
    }

    /**
     * The decryptors in descending typeId order, among a type of another group: the adapter filters and sorts.
     */
    private function invTypeRepository(): InvTypeRepository
    {
        $types = [self::type(self::TRITANIUM, 'Tritanium', self::MINERAL_GROUP_ID)];
        foreach (array_reverse(self::SDE_DECRYPTORS, true) as $typeId => [$name]) {
            $types[] = self::type($typeId, $name, self::DECRYPTOR_GROUP_ID);
        }

        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static function (array $criteria, ?array $orderBy = null) use ($types): array {
                self::assertSame(['group'], array_keys($criteria), 'decryptors are looked up by group');
                $groupId = $criteria['group'] instanceof InvGroup ? $criteria['group']->getGroupId() : $criteria['group'];
                $found = array_values(array_filter($types, static fn (InvType $type): bool => $type->getGroup()->getGroupId() === $groupId));
                if (null !== $orderBy && \array_key_exists('typeId', $orderBy)) {
                    usort($found, static fn (InvType $a, InvType $b): int => 'DESC' === strtoupper($orderBy['typeId'])
                        ? $b->getTypeId() <=> $a->getTypeId()
                        : $a->getTypeId() <=> $b->getTypeId());
                }

                return $found;
            },
        );

        return $repository;
    }

    private function dogmaRepository(): DgmTypeAttributeRepository
    {
        $repository = $this->createStub(DgmTypeAttributeRepository::class);
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->dogmaRows,
                static fn (DgmTypeAttribute $row): bool => $row->getTypeId() === $criteria['typeId']
                    && \in_array($row->getAttributeId(), (array) $criteria['attributeId'], true),
            )),
        );

        return $repository;
    }

    private static function type(int $typeId, string $name, int $groupId): InvType
    {
        return (new InvType())
            ->setTypeId($typeId)
            ->setTypeName($name)
            ->setGroup((new InvGroup())->setGroupId($groupId));
    }

    private static function dogmaRow(int $typeId, int $attributeId, int|float $value): DgmTypeAttribute
    {
        $row = (new DgmTypeAttribute())->setTypeId($typeId)->setAttributeId($attributeId);

        return \is_int($value) || floor($value) === $value
            ? $row->setValueInt((int) $value)
            : $row->setValueFloat($value);
    }
}
