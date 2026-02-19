<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Notification\UnreadCountProvider;

#[ApiResource(
    shortName: 'NotificationUnreadCount',
    description: 'Unread notification count for badge',
    operations: [
        new Get(
            uriTemplate: '/me/notifications/unread-count',
            provider: UnreadCountProvider::class,
            openapi: new Model\Operation(summary: 'Get unread notifications count', tags: ['Notifications']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class UnreadCountResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'unread-count';

    public int $count = 0;
}
