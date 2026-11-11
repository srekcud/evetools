<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Admin\RemoveFailedMessageProcessor;
use App\State\Processor\Admin\RetryFailedMessageProcessor;
use App\State\Provider\Admin\FailedMessageProvider;

#[ApiResource(
    shortName: 'AdminFailedMessage',
    description: 'One failed message of the failed transport (admin only)',
    operations: [
        new Post(
            uriTemplate: '/admin/failed-messages/{id}/retry',
            uriVariables: ['id' => new Link(fromClass: self::class)],
            processor: RetryFailedMessageProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            openapi: new Model\Operation(summary: 'Send a failed message back to its original transport', tags: ['Administration']),
        ),
        new Delete(
            uriTemplate: '/admin/failed-messages/{id}',
            uriVariables: ['id' => new Link(fromClass: self::class)],
            provider: FailedMessageProvider::class,
            processor: RemoveFailedMessageProcessor::class,
            openapi: new Model\Operation(summary: 'Remove a failed message without running it', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class FailedMessageResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
