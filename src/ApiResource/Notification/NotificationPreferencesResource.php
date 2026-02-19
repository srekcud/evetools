<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Notification\PreferencesInput;
use App\State\Processor\Notification\PreferencesProcessor;
use App\State\Provider\Notification\PreferencesProvider;

#[ApiResource(
    shortName: 'NotificationPreferences',
    description: 'User notification preferences by category',
    operations: [
        new Get(
            uriTemplate: '/me/notification-preferences',
            provider: PreferencesProvider::class,
            openapi: new Model\Operation(summary: 'Get notification preferences', tags: ['Notifications']),
        ),
        new Put(
            uriTemplate: '/me/notification-preferences',
            input: PreferencesInput::class,
            processor: PreferencesProcessor::class,
            openapi: new Model\Operation(summary: 'Save notification preferences', tags: ['Notifications']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class NotificationPreferencesResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'preferences';

    /** @var list<array{category: string, enabled: bool, thresholdMinutes: ?int, pushEnabled: bool}> */
    public array $preferences = [];
}
