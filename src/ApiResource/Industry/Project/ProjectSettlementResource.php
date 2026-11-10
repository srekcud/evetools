<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\ConfirmTransferReceivedInput;
use App\State\Processor\Industry\Project\ConfirmTransferReceivedProcessor;
use App\State\Provider\Industry\Project\ProjectSettlementProvider;

#[ApiResource(
    shortName: 'IndustryProjectSettlement',
    description: 'Transfers between the members of a group industry project, payment reference and checks before closing',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/settlement',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSettlementProvider::class,
            openapi: new Model\Operation(
                summary: 'Get project settlement',
                description: 'Returns the transfers that give every member their payout, the payment reference and the checks to read before closing the project',
                tags: ['Group Industry - Settlement'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/transfers/{fromParticipantId}/{toParticipantId}/receipt',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'fromParticipantId' => new Link(fromClass: ProjectParticipantResource::class),
                'toParticipantId' => new Link(fromClass: ProjectParticipantResource::class),
            ],
            status: 200,
            processor: ConfirmTransferReceivedProcessor::class,
            input: ConfirmTransferReceivedInput::class,
            openapi: new Model\Operation(
                summary: 'Tick or untick a transfer as received',
                description: 'The recipient of a transfer of the current plan ticks it as received (or unticks it)',
                tags: ['Group Industry - Settlement'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class ProjectSettlementResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    public string $reference;

    /**
     * @var list<array{fromParticipantId: string, fromName: string|null, toParticipantId: string, toName: string|null, amount: float, received: bool, journalVerified: bool, receivedAt: string|null, canConfirm: bool}>|null
     */
    public ?array $transfers = null;

    public ?string $transfersUnavailableReason = null;

    public float $roundingGap = 0.0;

    /**
     * @var array{pendingContributionCount: int, unsoldProducts: list<array{typeId: int, typeName: string, quantity: int}>, marginPercent: float|null, transfersTotal: int|null, transfersReceived: int|null}
     */
    public array $checks;
}
