<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Notification\PushSubscriptionInput;
use App\State\Processor\Notification\PushSubscriptionDeleteProcessor;
use App\State\Processor\Notification\PushSubscriptionProcessor;
use App\State\Provider\Notification\PushSubscriptionDeleteProvider;

#[ApiResource(
    shortName: 'PushSubscription',
    description: 'Web Push subscription management',
    operations: [
        new Post(
            uriTemplate: '/me/push-subscription',
            input: PushSubscriptionInput::class,
            processor: PushSubscriptionProcessor::class,
            openapi: new Model\Operation(summary: 'Register a push subscription', tags: ['Notifications']),
        ),
        new Delete(
            uriTemplate: '/me/push-subscription',
            provider: PushSubscriptionDeleteProvider::class,
            processor: PushSubscriptionDeleteProcessor::class,
            openapi: new Model\Operation(summary: 'Unregister all push subscriptions', tags: ['Notifications']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PushSubscriptionResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'push-subscription';

    public bool $success = true;
}
