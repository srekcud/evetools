<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Processor\MiningOperation\StopMiningOperationRecurrenceProcessor;
use App\State\Provider\MiningOperation\MiningOperationRecurrenceDeleteProvider;
use App\State\Provider\MiningOperation\MyMiningOperationRecurrencesProvider;

#[ApiResource(
    shortName: 'MiningOperationRecurrence',
    description: 'Rules that create a mining operation every week or at every chunk of a refinery',
    operations: [
        new GetCollection(
            uriTemplate: '/mining-operations/recurrences',
            provider: MyMiningOperationRecurrencesProvider::class,
            openapi: new Model\Operation(
                summary: 'List my active mining operation recurrences',
                description: 'Returns the active recurrences owned by the current user with the date of the next weekly occurrence',
                tags: ['Mining Operations'],
            ),
        ),
        new Delete(
            uriTemplate: '/mining-operations/recurrences/{id}',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningOperationRecurrenceDeleteProvider::class,
            processor: StopMiningOperationRecurrenceProcessor::class,
            openapi: new Model\Operation(
                summary: 'Stop a mining operation recurrence',
                description: 'Stops the recurrence: no occurrence is created any more, the operations already created are kept (owner only)',
                tags: ['Mining Operations'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationRecurrenceResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public ?string $kind = null;

    public ?string $label = null;

    public ?int $structureId = null;

    public ?string $structureName = null;

    public ?\DateTimeImmutable $nextOccurrenceAt = null;

    public ?\DateTimeImmutable $createdAt = null;
}
