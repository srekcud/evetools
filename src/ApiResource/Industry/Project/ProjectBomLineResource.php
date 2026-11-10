<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectBomProvider;

#[ApiResource(
    shortName: 'ProjectBomLine',
    description: 'Bill of Materials for a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/bom',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectBomProvider::class,
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
class ProjectBomLineResource
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

    /** @var array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}|null */
    public ?array $estimatedPriceSource = null;

    public bool $isJob;

    public ?string $jobGroup = null;

    public ?string $activityType = null;

    public ?int $parentTypeId = null;

    public ?int $meLevel = null;

    public ?int $teLevel = null;

    public ?int $runs = null;

    public bool $isFulfilled;

    /** @var list<array<string, mixed>> */
    public array $jobMatches = [];

    public int $matchedRuns = 0;

    /** @var list<array<string, mixed>> */
    public array $purchases = [];

    public int $purchasedQuantity = 0;

    /** @var list<array{participantId: string, characterName: string|null, quantity: int, isMine: bool}> */
    public array $reservations = [];

    public int $reservedQuantity = 0;

    public int $freeQuantity = 0;
}
