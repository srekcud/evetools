<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\GroupIndustry\RecordSaleInput;
use App\State\Processor\GroupIndustry\RecordSaleProcessor;
use App\State\Provider\GroupIndustry\GroupSaleCollectionProvider;

#[ApiResource(
    shortName: 'GroupIndustrySale',
    description: 'Sales recorded for a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/group-industry/projects/{projectId}/sales',
            provider: GroupSaleCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List project sales',
                description: 'Returns all sales recorded for the project',
                tags: ['Group Industry - Sales'],
            ),
        ),
        new Post(
            uriTemplate: '/group-industry/projects/{projectId}/sales',
            processor: RecordSaleProcessor::class,
            input: RecordSaleInput::class,
            openapi: new Model\Operation(
                summary: 'Record a sale',
                description: 'Records a new sale for the project (admin/owner only)',
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

    public string $recordedByCharacterName;

    public string $createdAt;
}
