<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\PurchaseDeliveryState;
use App\Service\Industry\Project\PurchaseOriginKind;
use App\Service\Industry\Project\PurchasesToDeliver;
use App\Service\Industry\Project\PurchaseToDeliver;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\Project\ProjectPurchasesToDeliverResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectPurchasesToDeliverResourceMapper::class)]
#[Group('issue-1085')]
final class ProjectPurchasesToDeliverResourceMapperTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int UNNAMED_TYPE = 999_085;
    private const int HUB = 30_001_001;
    private const string BOM_LINE_ID = '0198aaaa-bbbb-7ccc-8ddd-000000001085';

    public function testRowsKeepTheUnknownQuantityAsNullAndCarryTheTypeNames(): void
    {
        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key([self::TRITANIUM => 'Tritanium'], array_flip($typeIds)),
        );
        $mapper = new ProjectPurchasesToDeliverResourceMapper($typeNameResolver);

        $resource = $mapper->toResource(new IndustryProject(), new PurchasesToDeliver(ContainerStockState::Unknown, 2, [
            new PurchaseToDeliver(self::BOM_LINE_ID, self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 1_000, null, PurchaseDeliveryState::Unknown),
            new PurchaseToDeliver(self::BOM_LINE_ID, self::UNNAMED_TYPE, PurchaseOriginKind::Unknown, null, null, 5, 5, PurchaseDeliveryState::Missing),
        ]));

        self::assertSame(['unknown', 2], [$resource->stockState, $resource->purchaseCount]);
        self::assertSame(
            [
                ['bomLineId' => self::BOM_LINE_ID, 'typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'originKind' => 'other', 'solarSystemId' => self::HUB, 'solarSystemName' => 'C-J6MT', 'purchasedQuantity' => 1_000, 'missingQuantity' => null, 'deliveryState' => 'unknown'],
                ['bomLineId' => self::BOM_LINE_ID, 'typeId' => self::UNNAMED_TYPE, 'typeName' => null, 'originKind' => 'unknown', 'solarSystemId' => null, 'solarSystemName' => null, 'purchasedQuantity' => 5, 'missingQuantity' => 5, 'deliveryState' => 'missing'],
            ],
            $resource->rows,
        );
    }
}
