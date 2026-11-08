<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MiningOperation\MinedOreCompressedQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MinedOreCompressedQuantities::class)]
#[Group('issue-314')]
final class MinedOreCompressedQuantitiesTest extends TestCase
{
    private const int VELDSPAR = 1230;
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int CLEAR_ICICLE = 16262;
    private const int COMPRESSED_CLEAR_ICICLE = 28434;
    private const int MYSTERY_ORE = 99_314_001;
    private const int PORTIONLESS_ORE = 99_314_002;
    private const int COMPRESSED_PORTIONLESS_ORE = 99_314_003;
    private const int UNKNOWN_TO_SDE = 99_314_004;
    private const int STUBBORN_ORE = 99_314_005;
    private const int COMPRESSED_STUBBORN_ORE = 99_314_006;

    public function testAWholeNumberOfBatchesBecomesOneCompressedUnitPerBatch(): void
    {
        $result = $this->compressed([self::VELDSPAR => 20_000]);

        self::assertSame([self::COMPRESSED_VELDSPAR => 200], $result['quantities']);
        self::assertSame([], $result['unconvertedTypes']);
    }

    public function testAnIncompleteBatchIsLeftOutOfTheCompressedQuantity(): void
    {
        self::assertSame([self::COMPRESSED_VELDSPAR => 2], $this->compressed([self::VELDSPAR => 250])['quantities']);
    }

    public function testLessThanOneBatchDropsTheTypeWithoutReportingItAsUnconverted(): void
    {
        $result = $this->compressed([self::VELDSPAR => 50]);

        self::assertSame([], $result['quantities']);
        self::assertSame([], $result['unconvertedTypes']);
    }

    public function testIceWithAPortionOfOneKeepsItsQuantity(): void
    {
        self::assertSame([self::COMPRESSED_CLEAR_ICICLE => 5], $this->compressed([self::CLEAR_ICICLE => 5])['quantities']);
    }

    public function testSeveralMinedTypesAreConvertedEachToTheirOwnCompressedType(): void
    {
        $result = $this->compressed([self::VELDSPAR => 20_000, self::CLEAR_ICICLE => 5]);

        self::assertSame([self::COMPRESSED_VELDSPAR => 200, self::COMPRESSED_CLEAR_ICICLE => 5], $result['quantities']);
    }

    public function testATypeWithoutCompressedVariantIsListedAsUnconvertedAndNeverValued(): void
    {
        $result = $this->compressed([self::VELDSPAR => 20_000, self::MYSTERY_ORE => 1_000]);

        self::assertSame([self::COMPRESSED_VELDSPAR => 200], $result['quantities']);
        self::assertSame([['typeId' => self::MYSTERY_ORE, 'typeName' => 'Mystery Ore']], $result['unconvertedTypes']);
    }

    public function testATypeWhoseRawBatchSizeIsUnknownIsListedAsUnconverted(): void
    {
        $result = $this->compressed([self::PORTIONLESS_ORE => 1_000]);

        self::assertSame([], $result['quantities']);
        self::assertSame([['typeId' => self::PORTIONLESS_ORE, 'typeName' => 'Portionless Ore']], $result['unconvertedTypes']);
    }

    public function testATypeWhoseCompressedBatchSizeIsUnknownIsListedAsUnconverted(): void
    {
        $result = $this->compressed([self::STUBBORN_ORE => 1_000]);

        self::assertSame([], $result['quantities']);
        self::assertSame([['typeId' => self::STUBBORN_ORE, 'typeName' => 'Stubborn Ore']], $result['unconvertedTypes']);
    }

    public function testATypeUnknownToTheSdeIsListedWithoutAName(): void
    {
        $result = $this->compressed([self::UNKNOWN_TO_SDE => 1_000]);

        self::assertSame([], $result['quantities']);
        self::assertSame([['typeId' => self::UNKNOWN_TO_SDE, 'typeName' => null]], $result['unconvertedTypes']);
    }

    public function testNothingMinedQueriesNothingAndNonPositiveQuantitiesAreIgnored(): void
    {
        $repository = $this->createMock(InvTypeRepository::class);
        $repository->expects(self::never())->method('findByTypeIds');
        $repository->expects(self::never())->method('findByNames');

        $result = (new MinedOreCompressedQuantities($repository))->of([self::VELDSPAR => 0, self::CLEAR_ICICLE => -3]);

        self::assertSame(['quantities' => [], 'unconvertedTypes' => []], $result);
    }

    private function compressed(array $minedQuantities): array
    {
        $types = [
            self::VELDSPAR => $this->type(self::VELDSPAR, 'Veldspar', 100),
            self::COMPRESSED_VELDSPAR => $this->type(self::COMPRESSED_VELDSPAR, 'Compressed Veldspar', 1),
            self::CLEAR_ICICLE => $this->type(self::CLEAR_ICICLE, 'Clear Icicle', 1),
            self::COMPRESSED_CLEAR_ICICLE => $this->type(self::COMPRESSED_CLEAR_ICICLE, 'Compressed Clear Icicle', 1),
            self::MYSTERY_ORE => $this->type(self::MYSTERY_ORE, 'Mystery Ore', 100),
            self::PORTIONLESS_ORE => $this->type(self::PORTIONLESS_ORE, 'Portionless Ore', null),
            self::COMPRESSED_PORTIONLESS_ORE => $this->type(self::COMPRESSED_PORTIONLESS_ORE, 'Compressed Portionless Ore', 1),
            self::STUBBORN_ORE => $this->type(self::STUBBORN_ORE, 'Stubborn Ore', 100),
            self::COMPRESSED_STUBBORN_ORE => $this->type(self::COMPRESSED_STUBBORN_ORE, 'Compressed Stubborn Ore', null),
        ];
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($types, array_flip($typeIds)),
        );
        $repository->method('findByNames')->willReturnCallback(static function (array $names) use ($types): array {
            $byName = [];
            foreach ($types as $type) {
                $byName[$type->getTypeName()] = $type;
            }

            return array_intersect_key($byName, array_flip($names));
        });

        return (new MinedOreCompressedQuantities($repository))->of($minedQuantities);
    }

    private function type(int $typeId, string $typeName, ?int $portionSize): InvType
    {
        return (new InvType())->setTypeId($typeId)->setTypeName($typeName)->setPortionSize($portionSize)->setPublished(true);
    }
}
