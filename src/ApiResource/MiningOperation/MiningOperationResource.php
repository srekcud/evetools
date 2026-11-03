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
use App\ApiResource\Input\MiningOperation\StartMiningOperationInput;
use App\State\Processor\MiningOperation\CompleteMiningOperationProcessor;
use App\State\Processor\MiningOperation\CreateMiningOperationProcessor;
use App\State\Processor\MiningOperation\DeleteMiningOperationProcessor;
use App\State\Processor\MiningOperation\DepartMiningOperationProcessor;
use App\State\Processor\MiningOperation\EndMiningOperationProcessor;
use App\State\Processor\MiningOperation\FreezeMiningSharesProcessor;
use App\State\Processor\MiningOperation\LeaveMiningOperationProcessor;
use App\State\Processor\MiningOperation\RenewMiningOperationJoinCodeProcessor;
use App\State\Processor\MiningOperation\SettleMiningOperationProcessor;
use App\State\Processor\MiningOperation\StartMiningOperationProcessor;
use App\State\Provider\MiningOperation\ArchivedMiningOperationsProvider;
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
        new GetCollection(
            uriTemplate: '/mining-operations/archive',
            provider: ArchivedMiningOperationsProvider::class,
            openapi: new Model\Operation(
                summary: 'List archived mining operations',
                description: 'Returns the settled or completed operations the current user can read, latest archive first',
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
        new Post(
            uriTemplate: '/mining-operations/{id}/start',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: StartMiningOperationProcessor::class,
            input: StartMiningOperationInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Start a mining operation',
                description: 'Moves a planned operation to in progress and takes the start ledger snapshot of each accepted character (owner or admin)',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/end',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: EndMiningOperationProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'End a mining operation',
                description: 'Moves an operation in progress to closing and schedules the end ledger snapshot of each character (owner or admin)',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/depart',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: DepartMiningOperationProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Leave a mining operation early',
                description: 'Ends the operation for the current participant only: the end ledger snapshots of their characters are scheduled',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/freeze-shares',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: FreezeMiningSharesProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Freeze the shares of a mining operation',
                description: 'Copies the current unit prices and the mined value of each participant into a closing operation, which moves to shares (owner or admin)',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/settle',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: SettleMiningOperationProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Settle a mining operation',
                description: 'Moves an operation whose payments are all marked paid to settled and to the archive (owner or admin)',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{id}/complete',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: CompleteMiningOperationProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Complete an each-for-themselves mining operation',
                description: 'Freezes the prices and mined values of a closing each-for-themselves operation, which moves to completed and to the archive (owner or admin)',
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

    public ?\DateTimeImmutable $startedAt = null;

    public ?\DateTimeImmutable $endedAt = null;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    public ?\DateTimeImmutable $myDepartedAt = null;

    /** @var array<string, mixed>|null */
    public ?array $valuationSettings = null;

    public ?\DateTimeImmutable $sharesFrozenAt = null;

    public ?\DateTimeImmutable $archivedAt = null;

    public ?string $archivedByName = null;

    /** @var array<string, mixed>|null */
    public ?array $archiveSummary = null;
}
