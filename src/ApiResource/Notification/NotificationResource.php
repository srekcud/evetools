<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Notification\ClearReadProcessor;
use App\State\Processor\Notification\DeleteNotificationProcessor;
use App\State\Processor\Notification\MarkAllReadProcessor;
use App\State\Processor\Notification\MarkReadProcessor;
use App\State\Provider\Notification\NotificationCollectionProvider;
use App\State\Provider\Notification\NotificationProvider;

#[ApiResource(
    shortName: 'Notification',
    description: 'User notifications hub',
    operations: [
        new GetCollection(
            uriTemplate: '/me/notifications',
            provider: NotificationCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List notifications (paginated)',
                tags: ['Notifications'],
                parameters: [
                    new Model\Parameter(name: 'page', in: 'query', schema: ['type' => 'integer', 'default' => 1]),
                    new Model\Parameter(name: 'category', in: 'query', schema: ['type' => 'string']),
                    new Model\Parameter(name: 'isRead', in: 'query', schema: ['type' => 'boolean']),
                ],
            ),
        ),
        new Patch(
            uriTemplate: '/me/notifications/{id}/read',
            provider: NotificationProvider::class,
            processor: MarkReadProcessor::class,
            openapi: new Model\Operation(
                summary: 'Mark a notification as read',
                tags: ['Notifications'],
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
        new Post(
            uriTemplate: '/me/notifications/read-all',
            input: EmptyInput::class,
            processor: MarkAllReadProcessor::class,
            openapi: new Model\Operation(summary: 'Mark all notifications as read', tags: ['Notifications']),
        ),
        new Post(
            uriTemplate: '/me/notifications/clear-read',
            input: EmptyInput::class,
            processor: ClearReadProcessor::class,
            openapi: new Model\Operation(summary: 'Delete all read notifications', tags: ['Notifications']),
        ),
        new Delete(
            uriTemplate: '/me/notifications/{id}',
            provider: NotificationProvider::class,
            processor: DeleteNotificationProcessor::class,
            openapi: new Model\Operation(summary: 'Delete a notification', tags: ['Notifications']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class NotificationResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $category;

    public string $level;

    public string $title;

    public string $message;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public ?string $route = null;

    public bool $isRead = false;

    public string $createdAt;
}
