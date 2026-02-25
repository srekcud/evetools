<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ItemParserService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemParserService::class)]
class ItemParserServiceTest extends TestCase
{
    private InvTypeRepository&Stub $invTypeRepository;
    private ItemParserService $service;

    protected function setUp(): void
    {
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->service = new ItemParserService($this->invTypeRepository);
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createInvTypeStub(int $typeId, string $typeName, bool $published = true): InvType&Stub
    {
        $type = $this->createStub(InvType::class);
        $type->method('getTypeId')->willReturn($typeId);
        $type->method('getTypeName')->willReturn($typeName);
        $type->method('isPublished')->willReturn($published);

        return $type;
    }

    private function configureQueryBuilderForCaseInsensitiveLookup(InvType ...$results): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn($results);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->invTypeRepository->method('createQueryBuilder')->willReturn($queryBuilder);
    }

    // ===========================================
    // parseItemList() tests
    // ===========================================

    #[Test]
    public function parseItemListHandlesQuantityPrefixFormat(): void
    {
        $result = $this->service->parseItemList('10x Tritanium');

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesQuantitySuffixFormat(): void
    {
        $result = $this->service->parseItemList('Tritanium x10');

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesTabSeparatedFormat(): void
    {
        $result = $this->service->parseItemList("Tritanium\t10");

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesMultipleSpacesSeparator(): void
    {
        $result = $this->service->parseItemList('Tritanium    10');

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListIgnoresEmptyLines(): void
    {
        $text = "Tritanium x10\n\n\nPyerite x20\n";

        $result = $this->service->parseItemList($text);

        $this->assertCount(2, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame('Pyerite', $result[1]['name']);
    }

    #[Test]
    public function parseItemListMergesDuplicates(): void
    {
        $text = "Tritanium x10\nTritanium x20\ntritanium x5";

        $result = $this->service->parseItemList($text);

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(35, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesBulletPoints(): void
    {
        $text = "- Tritanium 10\n* Pyerite 20";

        $result = $this->service->parseItemList($text);

        $this->assertCount(2, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10, $result[0]['quantity']);
        $this->assertSame('Pyerite', $result[1]['name']);
        $this->assertSame(20, $result[1]['quantity']);
    }

    #[Test]
    public function parseItemListDefaultsToQuantityOneWithoutNumber(): void
    {
        $result = $this->service->parseItemList('Tritanium');

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(1, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesCommasInQuantity(): void
    {
        $result = $this->service->parseItemList('10,000x Tritanium');

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10000, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListReturnsEmptyForEmptyInput(): void
    {
        $result = $this->service->parseItemList('');

        $this->assertCount(0, $result);
    }

    #[Test]
    public function parseItemListReturnsEmptyForWhitespaceOnly(): void
    {
        $result = $this->service->parseItemList("   \n  \n  ");

        $this->assertCount(0, $result);
    }

    #[Test]
    public function parseItemListHandlesMultipleFormatsInSameBlock(): void
    {
        $text = "10x Tritanium\nPyerite x20\nMexallon\t30\nIsogen  40";

        $result = $this->service->parseItemList($text);

        $this->assertCount(4, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10, $result[0]['quantity']);
        $this->assertSame('Pyerite', $result[1]['name']);
        $this->assertSame(20, $result[1]['quantity']);
        $this->assertSame('Mexallon', $result[2]['name']);
        $this->assertSame(30, $result[2]['quantity']);
        $this->assertSame('Isogen', $result[3]['name']);
        $this->assertSame(40, $result[3]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesMultiWordItemNames(): void
    {
        $result = $this->service->parseItemList('Hammerhead II x5');

        $this->assertCount(1, $result);
        $this->assertSame('Hammerhead II', $result[0]['name']);
        $this->assertSame(5, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListHandlesWindowsLineEndings(): void
    {
        $text = "Tritanium x10\r\nPyerite x20";

        $result = $this->service->parseItemList($text);

        $this->assertCount(2, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame('Pyerite', $result[1]['name']);
    }

    #[Test]
    public function parseItemListHandlesItemNameWithTrailingQuantity(): void
    {
        $result = $this->service->parseItemList('Tritanium 500');

        $this->assertCount(1, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(500, $result[0]['quantity']);
    }

    #[Test]
    public function parseItemListIgnoresLineStartingWithDigitAndNoXFormat(): void
    {
        // Line starts with a digit but is not in "NNNx Name" format
        // parseLine falls through to the alphabetic check, which fails
        $result = $this->service->parseItemList('12345');

        $this->assertCount(0, $result);
    }

    // ===========================================
    // resolveItemNames() tests
    // ===========================================

    #[Test]
    public function resolveItemNamesFindsExactMatch(): void
    {
        $tritanium = $this->createInvTypeStub(34, 'Tritanium');

        $this->invTypeRepository
            ->method('findOneBy')
            ->willReturn($tritanium);

        $result = $this->service->resolveItemNames([
            ['name' => 'Tritanium', 'quantity' => 100],
        ]);

        $this->assertCount(1, $result['found']);
        $this->assertCount(0, $result['notFound']);
        $this->assertSame(34, $result['found'][0]['typeId']);
        $this->assertSame('Tritanium', $result['found'][0]['typeName']);
        $this->assertSame(100, $result['found'][0]['quantity']);
    }

    #[Test]
    public function resolveItemNamesFallsBackToCaseInsensitive(): void
    {
        $tritanium = $this->createInvTypeStub(34, 'Tritanium');

        $this->invTypeRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->configureQueryBuilderForCaseInsensitiveLookup($tritanium);

        $result = $this->service->resolveItemNames([
            ['name' => 'tritanium', 'quantity' => 50],
        ]);

        $this->assertCount(1, $result['found']);
        $this->assertCount(0, $result['notFound']);
        $this->assertSame(34, $result['found'][0]['typeId']);
        $this->assertSame('Tritanium', $result['found'][0]['typeName']);
    }

    #[Test]
    public function resolveItemNamesReturnsNotFoundForUnknownItems(): void
    {
        $this->invTypeRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->configureQueryBuilderForCaseInsensitiveLookup();

        $result = $this->service->resolveItemNames([
            ['name' => 'NonExistentItem', 'quantity' => 1],
        ]);

        $this->assertCount(0, $result['found']);
        $this->assertCount(1, $result['notFound']);
        $this->assertSame('NonExistentItem', $result['notFound'][0]);
    }

    #[Test]
    public function resolveItemNamesExcludesUnpublishedTypes(): void
    {
        $unpublished = $this->createInvTypeStub(99999, 'Hidden Item', published: false);

        $this->invTypeRepository
            ->method('findOneBy')
            ->willReturn($unpublished);

        $result = $this->service->resolveItemNames([
            ['name' => 'Hidden Item', 'quantity' => 1],
        ]);

        $this->assertCount(0, $result['found']);
        $this->assertCount(1, $result['notFound']);
        $this->assertSame('Hidden Item', $result['notFound'][0]);
    }

    #[Test]
    public function resolveItemNamesHandlesMixedFoundAndNotFound(): void
    {
        $tritanium = $this->createInvTypeStub(34, 'Tritanium');

        $this->invTypeRepository
            ->method('findOneBy')
            ->willReturnCallback(fn (array $criteria) => match ($criteria['typeName']) {
                'Tritanium' => $tritanium,
                default => null,
            });

        $this->configureQueryBuilderForCaseInsensitiveLookup();

        $result = $this->service->resolveItemNames([
            ['name' => 'Tritanium', 'quantity' => 100],
            ['name' => 'FakeOre', 'quantity' => 50],
        ]);

        $this->assertCount(1, $result['found']);
        $this->assertCount(1, $result['notFound']);
        $this->assertSame(34, $result['found'][0]['typeId']);
        $this->assertSame('FakeOre', $result['notFound'][0]);
    }

    #[Test]
    public function resolveItemNamesNormalizesMultipleSpacesInName(): void
    {
        $hammerhead = $this->createInvTypeStub(2185, 'Hammerhead II');

        // Name with extra spaces should be normalized to single space before lookup
        $this->invTypeRepository
            ->method('findOneBy')
            ->willReturn($hammerhead);

        $result = $this->service->resolveItemNames([
            ['name' => 'Hammerhead  II', 'quantity' => 5],
        ]);

        $this->assertCount(1, $result['found']);
        $this->assertSame(2185, $result['found'][0]['typeId']);
        $this->assertSame('Hammerhead II', $result['found'][0]['typeName']);
    }
}
