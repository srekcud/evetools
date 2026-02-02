<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Admin\QueueStatusProvider;

#[ApiResource(
    shortName: 'AdminQueue',
    description: 'Admin queue status',
    operations: [
        new Get(
            uriTemplate: '/admin/queues',
            provider: QueueStatusProvider::class,
            openapiContext: [
                'summary' => 'Get queue status',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class QueueResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'queues';

    public array $queues = [];
}
