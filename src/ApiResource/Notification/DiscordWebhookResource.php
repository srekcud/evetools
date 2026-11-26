<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Notification\DiscordWebhookInput;
use App\State\Processor\Notification\DiscordWebhookDeleteProcessor;
use App\State\Processor\Notification\DiscordWebhookSaveProcessor;
use App\State\Processor\Notification\DiscordWebhookTestProcessor;
use App\State\Provider\Notification\DiscordWebhookProvider;

#[ApiResource(
    shortName: 'DiscordWebhook',
    description: 'Discord webhook receiving the planetary alerts of the current user',
    operations: [
        new Get(
            uriTemplate: '/me/discord-webhook',
            provider: DiscordWebhookProvider::class,
            openapi: new Model\Operation(summary: 'Tell whether a Discord webhook is configured', tags: ['Notifications']),
        ),
        new Put(
            uriTemplate: '/me/discord-webhook',
            input: DiscordWebhookInput::class,
            processor: DiscordWebhookSaveProcessor::class,
            openapi: new Model\Operation(summary: 'Register or replace the Discord webhook', tags: ['Notifications']),
        ),
        new Delete(
            uriTemplate: '/me/discord-webhook',
            provider: DiscordWebhookProvider::class,
            processor: DiscordWebhookDeleteProcessor::class,
            openapi: new Model\Operation(summary: 'Remove the Discord webhook', tags: ['Notifications']),
        ),
        new Post(
            uriTemplate: '/me/discord-webhook/test',
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            processor: DiscordWebhookTestProcessor::class,
            openapi: new Model\Operation(summary: 'Send a test message to the Discord webhook', tags: ['Notifications']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class DiscordWebhookResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'discord-webhook';

    public bool $configured = false;
}
