<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\MiningOperation\CorrectMinedQuantityInput;
use App\State\Processor\MiningOperation\CorrectMinedQuantityProcessor;
use App\State\Processor\MiningOperation\RetryMiningLedgerSnapshotProcessor;

#[ApiResource(
    shortName: 'MiningOperationCharacter',
    description: 'Characters enrolled in a mining operation',
    operations: [
        new Post(
            uriTemplate: '/mining-operations/{operationId}/characters/{id}/snapshot-retry',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            processor: RetryMiningLedgerSnapshotProcessor::class,
            input: EmptyInput::class,
            output: false,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Retry a ledger snapshot',
                description: 'Reads again the start or end ledger snapshot of an enrolled character (character owner or operation admin)',
                tags: ['Mining Operations - Ledger'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{operationId}/characters/{id}/quantity-corrections',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            processor: CorrectMinedQuantityProcessor::class,
            input: CorrectMinedQuantityInput::class,
            output: false,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Correct a mined quantity',
                description: 'Replaces the mined quantity of a type for an enrolled character, keeping the measured value (owner or admin, closing only)',
                tags: ['Mining Operations - Ledger'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationCharacterResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;
}
