<?php

declare(strict_types=1);

namespace App\ApiResource\OpenWindow;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\OpenWindow\OpenWindowContractInput;
use App\ApiResource\Input\OpenWindow\OpenWindowInfoInput;
use App\ApiResource\Input\OpenWindow\OpenWindowMarketInput;
use App\State\Processor\OpenWindow\OpenWindowProcessor;

#[ApiResource(
    shortName: 'OpenWindow',
    description: 'Open in-game windows via ESI',
    operations: [
        new Post(
            uriTemplate: '/me/open-window/market',
            input: OpenWindowMarketInput::class,
            processor: OpenWindowProcessor::class,
            openapi: new Model\Operation(
                summary: 'Open market details window',
                description: 'Opens the in-game market details window for the given type ID',
                tags: ['Navigation'],
            ),
        ),
        new Post(
            uriTemplate: '/me/open-window/info',
            input: OpenWindowInfoInput::class,
            processor: OpenWindowProcessor::class,
            openapi: new Model\Operation(
                summary: 'Open information window',
                description: 'Opens the in-game information window for the given target ID (character, corporation, type, etc.)',
                tags: ['Navigation'],
            ),
        ),
        new Post(
            uriTemplate: '/me/open-window/contract',
            input: OpenWindowContractInput::class,
            processor: OpenWindowProcessor::class,
            openapi: new Model\Operation(
                summary: 'Open contract window',
                description: 'Opens the in-game contract window for the given contract ID',
                tags: ['Navigation'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class OpenWindowResource
{
    public bool $success;

    public ?string $error = null;
}
