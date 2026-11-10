<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MiningOperation\MiningOperationMinersEstimateProvider;

#[ApiResource(
    shortName: 'MiningOperationMinersEstimate',
    description: 'Miners needed to deplete a type of site before the next downtime, from the depleted sites of the user',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/miners-estimate',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningOperationMinersEstimateProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the miners needed to deplete a site before the next downtime',
                description: 'Computed on read from the depleted sites of the user linked to an operation (participants only)',
                tags: ['Mining Operations - Miners estimate'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationMinersEstimateResource
{
    public ?string $operationId = null;

    public ?\DateTimeImmutable $startAt = null;

    /** @var list<array<string, mixed>> */
    public array $types = [];
}
