<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\State\Processor\MiningOperation\CreateMiningOperationProcessor;
use App\State\Processor\MiningOperation\DeleteMiningOperationProcessor;
use App\State\Processor\MiningOperation\LeaveMiningOperationProcessor;
use App\State\Processor\MiningOperation\RenewMiningOperationJoinCodeProcessor;
use App\State\Provider\MiningOperation\AvailableMiningOperationsProvider;
use App\State\Provider\MiningOperation\MiningOperationDeleteProvider;
use App\State\Provider\MiningOperation\MiningOperationProvider;
use App\State\Provider\MiningOperation\MyMiningOperationsProvider;

#[ApiResource(
    shortName: 'MiningOperation',
    description: 'Mining operations gathering the characters of several players',
    operations: [
        new GetCollection(
            uriTemplate: '/mining-operations',
            provider: MyMiningOperationsProvider::class,
            openapi: new Model\Operation(
                summary: 'List my mining operations',
                description: 'Returns the operations the current user owns or takes part in, latest start first',
                tags: ['Mining Operations'],
            ),
        ),
        new GetCollection(
            uriTemplate: '/mining-operations/available',
            provider: AvailableMiningOperationsProvider::class,
            openapi: new Model\Operation(
                summary: 'List available mining operations',
                description: 'Returns the open corporation operations of the user corporation the user has no membership in',
                tags: ['Mining Operations'],
            ),
        ),
        new Get(
            uriTemplate: '/mining-operations/{id}',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningOperationProvider::class,
            openapi: new Model\Operation(
                summary: 'Get a mining operation',
                description: 'Returns the operation with the role and status of the current user',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations',
            processor: CreateMiningOperationProcessor::class,
            input: CreateMiningOperationInput::class,
            openapi: new Model\Operation(
                summary: 'Create a mining operation',
                description: 'Creates a planned operation owned by the current user, enrolled with the ticked characters',
                tags: ['Mining Operations'],
            ),
        ),
        new Delete(
            uriTemplate: '/mining-operations/{id}',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningOperationDeleteProvider::class,
            processor: DeleteMiningOperationProcessor::class,
            openapi: new Model\Operation(
                summary: 'Delete a mining operation',
                description: 'Deletes a planned operation with its members and enrolled characters (owner only)',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/join-code',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: RenewMiningOperationJoinCodeProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Renew the join code',
                description: 'Replaces the join code: the previous invitation link stops working (owner or admin)',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/leave',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: LeaveMiningOperationProcessor::class,
            input: EmptyInput::class,
            output: false,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Leave a mining operation',
                description: 'Removes the membership of the current user and frees the enrolled characters',
                tags: ['Mining Operations'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public ?string $label = null;

    public ?\DateTimeImmutable $plannedStartAt = null;

    public ?string $status = null;

    public ?string $visibility = null;

    public ?string $splitMode = null;

    public ?int $corporationId = null;

    public ?string $ownerCharacterName = null;

    public int $participantsCount = 0;

    public int $charactersCount = 0;

    public ?string $joinCode = null;

    public ?string $myRole = null;

    public ?string $myStatus = null;

    public ?\DateTimeImmutable $createdAt = null;
}
