<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningOperation\UpdateMiningFleetStorageInput;
use App\State\Processor\MiningOperation\UpdateMiningFleetStorageProcessor;
use App\State\Provider\MiningOperation\MiningFleetStorageProvider;

#[ApiResource(
    shortName: 'MiningFleetStorage',
    description: 'Fleet storage recipient, corporation container and declarations of a mining operation',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/fleet-storage',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningFleetStorageProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the fleet storage of a mining operation',
                description: 'Returns the recipient, the corporation container and the declaration of each participant (participants only)',
                tags: ['Mining Operations - Fleet storage'],
            ),
        ),
        new Patch(
            uriTemplate: '/mining-operations/{operationId}/fleet-storage',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            inputFormats: ['json' => ['application/merge-patch+json']],
            provider: MiningFleetStorageProvider::class,
            processor: UpdateMiningFleetStorageProcessor::class,
            input: UpdateMiningFleetStorageInput::class,
            openapi: new Model\Operation(
                summary: 'Update the fleet storage of a mining operation',
                description: 'Chooses the fleet storage recipient and the corporation container until the shares are frozen (owner or admin)',
                tags: ['Mining Operations - Fleet storage'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningFleetStorageResource
{
    public ?string $operationId = null;

    public bool $available = false;

    public bool $editable = false;

    public ?string $recipientMemberId = null;

    public ?string $recipientName = null;

    public ?string $containerName = null;

    /** @var list<array<string, mixed>> */
    public array $declarations = [];
}
