<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectPurchasesToDeliverResource;
use App\Entity\IndustryProject;
use App\Service\Industry\Project\PurchasesToDeliver;
use App\Service\Industry\Project\PurchaseToDeliver;
use App\Service\TypeNameResolver;

final readonly class ProjectPurchasesToDeliverResourceMapper
{
    public function __construct(
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function toResource(IndustryProject $project, PurchasesToDeliver $purchases): ProjectPurchasesToDeliverResource
    {
        $typeNames = $this->typeNameResolver->findNames(array_map(static fn (PurchaseToDeliver $row): int => $row->typeId, $purchases->rows));

        $resource = new ProjectPurchasesToDeliverResource();
        $resource->id = $project->getId()->toRfc4122();
        $resource->stockState = $purchases->stockState->value;
        $resource->purchaseCount = $purchases->purchaseCount;
        $resource->rows = array_map(
            static fn (PurchaseToDeliver $row): array => [
                'bomLineId' => $row->bomLineId,
                'typeId' => $row->typeId,
                'typeName' => $typeNames[$row->typeId] ?? null,
                'originKind' => $row->originKind->value,
                'solarSystemId' => $row->solarSystemId,
                'solarSystemName' => $row->solarSystemName,
                'purchasedQuantity' => $row->purchasedQuantity,
                'missingQuantity' => $row->missingQuantity,
                'deliveryState' => $row->deliveryState->value,
            ],
            $purchases->rows,
        );

        return $resource;
    }
}
