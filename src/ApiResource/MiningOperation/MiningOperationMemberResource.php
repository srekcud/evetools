<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningOperation\UpdateMiningOperationMemberInput;
use App\State\Processor\MiningOperation\RemoveMiningOperationMemberProcessor;
use App\State\Processor\MiningOperation\UpdateMiningOperationMemberProcessor;
use App\State\Provider\MiningOperation\MiningOperationMemberCollectionProvider;
use App\State\Provider\MiningOperation\MiningOperationMemberItemProvider;

#[ApiResource(
    shortName: 'MiningOperationMember',
    description: 'Members of a mining operation',
    operations: [
        new GetCollection(
            uriTemplate: '/mining-operations/{operationId}/members',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningOperationMemberCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List operation members',
                description: 'Returns the accepted and pending members with their enrolled characters (participants only)',
                tags: ['Mining Operations - Members'],
            ),
        ),
        new Patch(
            uriTemplate: '/mining-operations/{operationId}/members/{id}',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningOperationMemberItemProvider::class,
            processor: UpdateMiningOperationMemberProcessor::class,
            input: UpdateMiningOperationMemberInput::class,
            openapi: new Model\Operation(
                summary: 'Update a member',
                description: 'Accept a pending member or change a role (owner or admin only)',
                tags: ['Mining Operations - Members'],
            ),
        ),
        new Delete(
            uriTemplate: '/mining-operations/{operationId}/members/{id}',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningOperationMemberItemProvider::class,
            processor: RemoveMiningOperationMemberProcessor::class,
            openapi: new Model\Operation(
                summary: 'Remove a member',
                description: 'Refuses a request or removes a member and frees the enrolled characters (owner or admin only)',
                tags: ['Mining Operations - Members'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationMemberResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public ?string $characterName = null;

    public ?int $corporationId = null;

    public ?string $role = null;

    public ?string $status = null;

    public ?\DateTimeImmutable $joinedAt = null;

    public ?\DateTimeImmutable $departedAt = null;

    /** @var list<array{id: string, eveCharacterId: int, name: string}> */
    public array $characters = [];
}
