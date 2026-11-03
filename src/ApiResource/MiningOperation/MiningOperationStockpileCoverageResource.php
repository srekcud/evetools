<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MiningOperation\MiningOperationStockpileCoverageProvider;

#[ApiResource(
    shortName: 'MiningOperationStockpileCoverage',
    description: 'Share of the Stockpile mineral deficit covered by the ore a participant mined during an operation',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/stockpile-coverage',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningOperationStockpileCoverageProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the Stockpile deficit coverage of a mining operation',
                description: 'Returns, for each mineral the participant mined ore reprocesses into, the produced quantity and the share of the Stockpile deficit it covers (participants only, own part only)',
                tags: ['Mining Operations - Stockpile coverage'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationStockpileCoverageResource
{
    public ?string $operationId = null;

    public ?string $state = null;

    public ?string $unknownReason = null;

    public bool $yieldKnown = false;

    public ?float $reprocessYield = null;

    /** @var list<array<string, mixed>> */
    public array $minerals = [];
}
