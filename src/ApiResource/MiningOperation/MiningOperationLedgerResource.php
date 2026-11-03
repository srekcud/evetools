<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MiningOperation\MiningOperationLedgerProvider;

#[ApiResource(
    shortName: 'MiningOperationLedger',
    description: 'Mined quantities of a mining operation, from the ledger snapshots of its characters',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/ledger',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningOperationLedgerProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the ledger of a mining operation',
                description: 'Returns the ledger snapshots and mined quantities by participant, character and type (participants only)',
                tags: ['Mining Operations - Ledger'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationLedgerResource
{
    public ?string $operationId = null;

    public ?string $status = null;

    public ?\DateTimeImmutable $startedAt = null;

    public ?\DateTimeImmutable $endedAt = null;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    public ?\DateTimeImmutable $endSnapshotsReadyAt = null;

    public bool $endSnapshotsPending = false;

    public int $endSnapshotsSettled = 0;

    public int $endSnapshotsTotal = 0;

    /** @var list<array<string, mixed>> */
    public array $participants = [];
}
