<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Admin\FailedMessageClassInput;
use App\State\Processor\Admin\RemoveFailedMessageClassProcessor;
use App\State\Processor\Admin\RetryFailedMessageClassProcessor;

#[ApiResource(
    shortName: 'AdminFailedMessageClassAction',
    description: 'Batch action on the failed messages of one message class (admin only)',
    operations: [
        new Post(
            uriTemplate: '/admin/failed-messages/retry-class',
            processor: RetryFailedMessageClassProcessor::class,
            input: FailedMessageClassInput::class,
            status: 200,
            openapi: new Model\Operation(summary: 'Send the failed messages of a message class back to their original transport', tags: ['Administration']),
        ),
        new Post(
            uriTemplate: '/admin/failed-messages/remove-class',
            processor: RemoveFailedMessageClassProcessor::class,
            input: FailedMessageClassInput::class,
            status: 200,
            openapi: new Model\Operation(summary: 'Remove the failed messages of a message class without running them', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class FailedMessageClassActionResource
{
    public int $processed = 0;

    public int $remaining = 0;
}
