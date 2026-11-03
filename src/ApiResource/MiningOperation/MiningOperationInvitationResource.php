<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningOperation\JoinMiningOperationInput;
use App\State\Processor\MiningOperation\JoinMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationInvitationProvider;

#[ApiResource(
    shortName: 'MiningOperationInvitation',
    description: 'Preview of a mining operation reached through its join code',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/join/{code}',
            uriVariables: [
                'code' => new Link(fromClass: self::class, identifiers: ['code']),
            ],
            provider: MiningOperationInvitationProvider::class,
            openapi: new Model\Operation(
                summary: 'Preview an operation by its join code',
                description: 'Returns the operation, the role of the current user and the eligibility of each of his characters',
                tags: ['Mining Operations'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/join/{code}',
            uriVariables: [
                'code' => new Link(fromClass: self::class, identifiers: ['code']),
            ],
            processor: JoinMiningOperationProcessor::class,
            input: JoinMiningOperationInput::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Join an operation by its join code',
                description: 'Creates the membership with the ticked characters. Members of the operation corporation are accepted automatically.',
                tags: ['Mining Operations'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationInvitationResource
{
    #[ApiProperty(identifier: true)]
    public ?string $code = null;

    public ?string $operationId = null;

    public ?string $label = null;

    public ?string $ownerCharacterName = null;

    public ?\DateTimeImmutable $plannedStartAt = null;

    public ?string $status = null;

    public ?string $visibility = null;

    public ?string $splitMode = null;

    public int $participantsCount = 0;

    public bool $autoAccepted = false;

    public ?string $myRole = null;

    public ?string $myStatus = null;

    /** @var list<array{id: string, eveCharacterId: int, name: string, isMain: bool, eligibility: string, busyOperationLabel: ?string}> */
    public array $characters = [];

    /** @var array<string, mixed>|null */
    public ?array $valuationSettings = null;
}
