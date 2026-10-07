<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ItemParserService;
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

    /**
     * Replaces the repository with a mock expecting a single batched lookup.
     *
     * @param list<string>            $requestedNames names after space normalization
     * @param array<string, InvType>  $typesByRequestedName
     */
    private function expectSingleFindByNamesCall(array $requestedNames, array $typesByRequestedName): void
    {
        $invTypeRepository = $this->createMock(InvTypeRepository::class);
        $invTypeRepository
            ->expects($this->once())
            ->method('findByNames')
            ->with($requestedNames)
            ->willReturn($typesByRequestedName);

        $this->service = new ItemParserService($invTypeRepository);
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
        $this->expectSingleFindByNamesCall(['Tritanium'], ['Tritanium' => $tritanium]);

        $result = $this->service->resolveItemNames([
            ['name' => 'Tritanium', 'quantity' => 100],
        ]);

        $this->assertSame([
            'found' => [['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100]],
            'notFound' => [],
        ], $result);
    }

    #[Test]
    public function resolveItemNamesFindsTypeWrittenInAnotherCase(): void
    {
        $tritanium = $this->createInvTypeStub(34, 'Tritanium');
        // The repository keys by the requested name; the canonical SDE name comes from the type.
        $this->expectSingleFindByNamesCall(['tritanium'], ['tritanium' => $tritanium]);

        $result = $this->service->resolveItemNames([
            ['name' => 'tritanium', 'quantity' => 50],
        ]);

        $this->assertSame([
            'found' => [['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 50]],
            'notFound' => [],
        ], $result);
    }

    #[Test]
    public function resolveItemNamesReturnsNotFoundForUnknownItems(): void
    {
        $this->expectSingleFindByNamesCall(['NonExistentItem'], []);

        $result = $this->service->resolveItemNames([
            ['name' => 'NonExistentItem', 'quantity' => 1],
        ]);

        $this->assertSame(['found' => [], 'notFound' => ['NonExistentItem']], $result);
    }

    #[Test]
    public function resolveItemNamesExcludesUnpublishedTypes(): void
    {
        $unpublished = $this->createInvTypeStub(99999, 'Hidden Item', published: false);
        $this->expectSingleFindByNamesCall(['Hidden Item'], ['Hidden Item' => $unpublished]);

        $result = $this->service->resolveItemNames([
            ['name' => 'Hidden Item', 'quantity' => 1],
        ]);

        $this->assertSame(['found' => [], 'notFound' => ['Hidden Item']], $result);
    }

    #[Test]
    public function resolveItemNamesHandlesMixedFoundAndNotFound(): void
    {
        $tritanium = $this->createInvTypeStub(34, 'Tritanium');
        $this->expectSingleFindByNamesCall(['Tritanium', 'FakeOre'], ['Tritanium' => $tritanium]);

        $result = $this->service->resolveItemNames([
            ['name' => 'Tritanium', 'quantity' => 100],
            ['name' => 'FakeOre', 'quantity' => 50],
        ]);

        $this->assertSame([
            'found' => [['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100]],
            'notFound' => ['FakeOre'],
        ], $result);
    }

    #[Test]
    public function resolveItemNamesNormalizesMultipleSpacesInName(): void
    {
        $hammerhead = $this->createInvTypeStub(2185, 'Hammerhead II');
        // 'Hammerhead  II' (two spaces) must be looked up as 'Hammerhead II'.
        $this->expectSingleFindByNamesCall(['Hammerhead II'], ['Hammerhead II' => $hammerhead]);

        $result = $this->service->resolveItemNames([
            ['name' => 'Hammerhead  II', 'quantity' => 5],
        ]);

        $this->assertSame([
            'found' => [['typeId' => 2185, 'typeName' => 'Hammerhead II', 'quantity' => 5]],
            'notFound' => [],
        ], $result);
    }
}
