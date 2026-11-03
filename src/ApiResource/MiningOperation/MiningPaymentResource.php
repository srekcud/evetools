<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningOperation\UpdateMiningPaymentInput;
use App\State\Processor\MiningOperation\UpdateMiningPaymentProcessor;
use App\State\Provider\MiningOperation\MiningPaymentCollectionProvider;
use App\State\Provider\MiningOperation\MiningPaymentItemProvider;

#[ApiResource(
    shortName: 'MiningPayment',
    description: 'Payments owed between the participants of a mining operation once its shares are frozen',
    operations: [
        new GetCollection(
            uriTemplate: '/mining-operations/{operationId}/payments',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningPaymentCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List the payments of a mining operation',
                description: 'Returns the payments in position order (participants, or readers of an archived operation)',
                tags: ['Mining Operations - Payments'],
            ),
        ),
        new Patch(
            uriTemplate: '/mining-operations/{operationId}/payments/{id}',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            inputFormats: ['json' => ['application/merge-patch+json']],
            provider: MiningPaymentItemProvider::class,
            processor: UpdateMiningPaymentProcessor::class,
            input: UpdateMiningPaymentInput::class,
            openapi: new Model\Operation(
                summary: 'Mark a payment paid or unpaid',
                description: 'Marks a payment paid or unpaid while the shares are frozen (payer, owner or admin)',
                tags: ['Mining Operations - Payments'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningPaymentResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public int $position = 0;

    public ?string $kind = null;

    public ?string $payerMemberId = null;

    public ?string $payerName = null;

    public ?string $beneficiaryMemberId = null;

    public ?string $beneficiaryName = null;

    public int $amount = 0;

    public bool $paid = false;

    public ?\DateTimeImmutable $paidAt = null;

    public ?string $paidByName = null;
}
