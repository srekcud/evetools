<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\GroupIndustry\GroupBomProvider;

#[ApiResource(
    shortName: 'GroupIndustryBom',
    description: 'Bill of Materials for a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/group-industry/projects/{projectId}/bom',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
            ],
            provider: GroupBomProvider::class,
            openapi: new Model\Operation(
                summary: 'Get project BOM',
                description: 'Returns the full Bill of Materials (materials + jobs) for the project',
                tags: ['Group Industry - BOM'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class GroupIndustryBomResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $typeId;

    public string $typeName;

    public int $requiredQuantity;

    public int $fulfilledQuantity;

    public int $remainingQuantity;

    public float $fulfillmentPercent;

    public ?float $estimatedPrice = null;

    public ?float $estimatedTotal = null;

    public bool $isJob;

    public ?string $jobGroup = null;

    public ?string $activityType = null;

    public ?int $parentTypeId = null;

    public ?int $meLevel = null;

    public ?int $teLevel = null;

    public ?int $runs = null;

    public bool $isFulfilled;
}
