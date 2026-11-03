<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MiningOperation\MiningFleetStorageVerificationProvider;

#[ApiResource(
    shortName: 'MiningFleetStorageVerification',
    description: 'Check of the delivered ore against the named corporation container',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/fleet-storage/verification',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningFleetStorageVerificationProvider::class,
            openapi: new Model\Operation(
                summary: 'Check the fleet storage of a mining operation',
                description: 'Compares the delivered quantities with the named container of the shared corporation assets (participants only)',
                tags: ['Mining Operations - Fleet storage'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningFleetStorageVerificationResource
{
    public ?string $operationId = null;

    public ?string $status = null;

    public ?string $unknownReason = null;

    public ?string $containerName = null;

    public ?\DateTimeImmutable $assetsReadAt = null;

    /** @var list<array<string, mixed>> */
    public array $lines = [];
}
