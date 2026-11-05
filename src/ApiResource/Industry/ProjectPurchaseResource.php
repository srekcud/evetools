<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\GroupIndustry\GroupIndustryBomResource;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\Entity\IndustryProjectPurchase;
use App\State\Processor\Industry\CreatePurchaseProcessor;
use App\State\Processor\Industry\DeletePurchaseProcessor;
use App\State\Provider\Industry\PurchaseDeleteProvider;

#[ApiResource(
    shortName: 'ProjectPurchase',
    description: 'Purchase linked to a line of the project BOM',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/purchases',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'lineId' => new Link(fromClass: GroupIndustryBomResource::class),
            ],
            processor: CreatePurchaseProcessor::class,
            input: CreatePurchaseInput::class,
            openapi: new Model\Operation(summary: 'Create purchase', description: 'Links a purchase (ESI wallet or manual) to a BOM line', tags: ['Industry - Projects']),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/purchases/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: PurchaseDeleteProvider::class,
            processor: DeletePurchaseProcessor::class,
            openapi: new Model\Operation(summary: 'Delete purchase', description: 'Removes a purchase link from a BOM line', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProjectPurchaseResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $bomLineId;

    public int $typeId;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    public string $source;

    public ?string $transactionId = null;

    public string $createdAt;

    public static function fromEntity(IndustryProjectPurchase $purchase): self
    {
        $resource = new self();
        $resource->id = (string) $purchase->getId();
        $resource->bomLineId = $purchase->getBomLine()->getId()->toRfc4122();
        $resource->typeId = $purchase->getTypeId();
        $resource->quantity = $purchase->getQuantity();
        $resource->unitPrice = $purchase->getUnitPrice();
        $resource->totalPrice = $purchase->getTotalPrice();
        $resource->source = $purchase->getSource();
        $resource->transactionId = $purchase->getTransaction() === null ? null : (string) $purchase->getTransaction()->getId();
        $resource->createdAt = $purchase->getCreatedAt()->format('c');

        return $resource;
    }
}
