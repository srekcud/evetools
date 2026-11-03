<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningOperation\DeclareMiningFleetStorageInput;
use App\State\Processor\MiningOperation\DeclareMiningFleetStorageProcessor;
use App\State\Provider\MiningOperation\MiningFleetStorageDeclarationProvider;

#[ApiResource(
    shortName: 'MiningFleetStorageDeclaration',
    description: 'Fleet storage declaration of a participant of a mining operation',
    operations: [
        new Patch(
            uriTemplate: '/mining-operations/{operationId}/fleet-storage/declarations/{memberId}',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
                'memberId' => new Link(fromClass: MiningOperationMemberResource::class),
            ],
            inputFormats: ['json' => ['application/merge-patch+json']],
            provider: MiningFleetStorageDeclarationProvider::class,
            processor: DeclareMiningFleetStorageProcessor::class,
            input: DeclareMiningFleetStorageInput::class,
            output: MiningFleetStorageResource::class,
            openapi: new Model\Operation(
                summary: 'Declare the fleet storage of a participant',
                description: 'Declares whether a participant keeps, delivers or partly delivers their ore to the recipient (the participant, the owner or an admin)',
                tags: ['Mining Operations - Fleet storage'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningFleetStorageDeclarationResource
{
    public ?string $memberId = null;
}
