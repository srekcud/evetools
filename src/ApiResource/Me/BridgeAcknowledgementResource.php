<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Me\CreateBridgeAcknowledgementInput;
use App\State\Processor\Me\AcknowledgeBridgeTodoProcessor;
use App\State\Processor\Me\RemoveBridgeAcknowledgementProcessor;
use App\State\Provider\Me\BridgeAcknowledgementCollectionProvider;
use App\State\Provider\Me\BridgeAcknowledgementItemProvider;

#[ApiResource(
    shortName: 'BridgeAcknowledgement',
    description: 'Acknowledged to-do lines of the bridge',
    operations: [
        new GetCollection(
            uriTemplate: '/me/bridge/acknowledgements',
            provider: BridgeAcknowledgementCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List the acknowledged bridge to-do lines', tags: ['Bridge']),
        ),
        new Get(
            uriTemplate: '/me/bridge/acknowledgements/{itemKey}',
            provider: BridgeAcknowledgementItemProvider::class,
            openapi: new Model\Operation(summary: 'Get an acknowledgement (used for IRI resolution)', tags: ['Bridge']),
        ),
        new Post(
            uriTemplate: '/me/bridge/acknowledgements',
            input: CreateBridgeAcknowledgementInput::class,
            processor: AcknowledgeBridgeTodoProcessor::class,
            openapi: new Model\Operation(summary: 'Acknowledge a bridge to-do line at its current count', tags: ['Bridge']),
        ),
        new Delete(
            uriTemplate: '/me/bridge/acknowledgements/{itemKey}',
            provider: BridgeAcknowledgementItemProvider::class,
            processor: RemoveBridgeAcknowledgementProcessor::class,
            openapi: new Model\Operation(summary: 'Remove an acknowledgement', tags: ['Bridge']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class BridgeAcknowledgementResource
{
    #[ApiProperty(identifier: true)]
    public string $itemKey = '';

    public int $acknowledgedCount = 0;
}
