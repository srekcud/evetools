<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\GroupIndustry\RecordSaleInput;
use App\ApiResource\Input\GroupIndustry\UpdateSaleInput;
use App\Entity\GroupIndustrySale;
use App\Service\GroupIndustry\PilotName;
use App\State\Processor\GroupIndustry\DeleteSaleProcessor;
use App\State\Processor\GroupIndustry\RecordSaleProcessor;
use App\State\Processor\GroupIndustry\UpdateSaleProcessor;
use App\State\Provider\GroupIndustry\GroupSaleCollectionProvider;
use App\State\Provider\GroupIndustry\GroupSaleItemProvider;

#[ApiResource(
    shortName: 'GroupIndustrySale',
    description: 'Sales recorded for a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/group-industry/projects/{projectId}/sales',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
            ],
            provider: GroupSaleCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List project sales',
                description: 'Returns all sales recorded for the project',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Post(
            uriTemplate: '/group-industry/projects/{projectId}/sales',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
            ],
            processor: RecordSaleProcessor::class,
            input: RecordSaleInput::class,
            openapi: new Model\Operation(
                summary: 'Record a sale',
                description: 'Records a new sale for the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Patch(
            uriTemplate: '/group-industry/projects/{projectId}/sales/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: GroupSaleItemProvider::class,
            processor: UpdateSaleProcessor::class,
            input: UpdateSaleInput::class,
            openapi: new Model\Operation(
                summary: 'Correct a sale',
                description: 'Corrects a recorded sale of the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Delete(
            uriTemplate: '/group-industry/projects/{projectId}/sales/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: GroupSaleItemProvider::class,
            processor: DeleteSaleProcessor::class,
            openapi: new Model\Operation(
                summary: 'Delete a sale',
                description: 'Deletes a recorded sale of the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class GroupIndustrySaleResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    public ?string $venue = null;

    public string $soldAt;

    public ?string $recordedByCharacterName = null;

    public string $createdAt;

    public static function fromSale(GroupIndustrySale $sale): self
    {
        $resource = new self();
        $resource->id = $sale->getId()->toRfc4122();
        $resource->typeId = $sale->getTypeId();
        $resource->typeName = $sale->getTypeName();
        $resource->quantity = $sale->getQuantity();
        $resource->unitPrice = $sale->getUnitPrice();
        $resource->totalPrice = $sale->getTotalPrice();
        $resource->venue = $sale->getVenue();
        $resource->soldAt = $sale->getSoldAt()->format(\DateTimeInterface::ATOM);
        $resource->recordedByCharacterName = PilotName::of($sale->getRecordedBy());
        $resource->createdAt = $sale->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
