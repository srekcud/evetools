<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Pve;

use App\ApiResource\Pve\CorporationProjectErrorResource;
use App\ApiResource\Pve\DetectedSaleResource;
use App\ApiResource\Pve\ScanLootSalesResultResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanLootSalesResultResource::class)]
#[CoversClass(CorporationProjectErrorResource::class)]
#[CoversClass(DetectedSaleResource::class)]
#[Group('issue-243')]
final class ScanLootSalesResultResourceTest extends TestCase
{
    public function testCorporationProjectErrorsReplaceTheCorporationsWithoutValidCharacter(): void
    {
        $resource = new ScanLootSalesResultResource();

        $this->assertFalse(property_exists(ScanLootSalesResultResource::class, 'corporationsWithoutValidCharacter'));
        $this->assertSame([], $resource->corporationProjectErrors);
    }

    public function testScannedTransactionsAndContractsCanBeUnknown(): void
    {
        $this->assertSame('?int', $this->propertyType(ScanLootSalesResultResource::class, 'scannedTransactions'));
        $this->assertSame('?int', $this->propertyType(ScanLootSalesResultResource::class, 'scannedContracts'));
        $this->assertSame('?int', $this->propertyType(ScanLootSalesResultResource::class, 'scannedProjects'));
    }

    public function testCorporationProjectErrorNamesTheCorporationTheProjectTheCharacterTheReasonAndTheHttpStatus(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $property): array => [$property->getName(), $this->propertyType(CorporationProjectErrorResource::class, $property->getName())],
            (new \ReflectionClass(CorporationProjectErrorResource::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        $this->assertSame([
            ['corporationName', 'string'],
            ['projectName', '?string'],
            ['characterName', '?string'],
            ['reason', 'string'],
            ['httpStatus', '?int'],
        ], $properties);
    }

    public function testDetectedSalePriceCanBeUnknown(): void
    {
        $this->assertSame('?float', $this->propertyType(DetectedSaleResource::class, 'price'));
    }

    private function propertyType(string $className, string $propertyName): string
    {
        return (string) (new \ReflectionProperty($className, $propertyName))->getType();
    }
}
