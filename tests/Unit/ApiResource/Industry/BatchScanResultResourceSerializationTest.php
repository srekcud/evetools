<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Industry;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\SerializerContextBuilderInterface;
use App\ApiResource\Industry\BatchScanResultResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(BatchScanResultResource::class)]
#[Group('issue-884')]
final class BatchScanResultResourceSerializationTest extends KernelTestCase
{
    public function testEveryKeyOfAScannedRowWithoutSellPriceIsSerializedAndTheUnknownOnesAreNull(): void
    {
        $resource = new BatchScanResultResource();
        $resource->typeId = 585;
        $resource->sellPrice = null;
        $resource->marginPercent = null;
        $resource->unknownReason = 'missing_sale_price';

        $serialized = $this->serialize($resource);

        foreach (['typeName', 'marginPercent', 'profitPerUnit', 'dailyVolume', 'iskPerDay', 'materialCost', 'importCost', 'exportCost', 'sellPrice', 'bpcCostPerRun', 'contractSales', 'unknownReasonStructureId'] as $nullableKey) {
            $this->assertArrayHasKey($nullableKey, $serialized, $nullableKey);
            $this->assertNull($serialized[$nullableKey], $nullableKey);
        }
        $this->assertSame('missing_sale_price', $serialized['unknownReason']);
        $this->assertSame(585, $serialized['typeId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(BatchScanResultResource $resource): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $operation = $container->get(ResourceMetadataCollectionFactoryInterface::class)
            ->create(BatchScanResultResource::class)
            ->getOperation('_api_/industry/profit-scan_get_collection');
        self::assertInstanceOf(HttpOperation::class, $operation);

        $context = $container->get(SerializerContextBuilderInterface::class)->createFromRequest(
            Request::create('/api/industry/profit-scan'),
            true,
            ['resource_class' => BatchScanResultResource::class, 'operation' => $operation],
        );

        return $container->get('api_platform.serializer')->normalize($resource, 'json', $context);
    }
}
