<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Admin\FailedMessagesProvider;

#[ApiResource(
    shortName: 'AdminFailedMessages',
    description: 'Failed messages of the failed transport, grouped by message class (admin only)',
    operations: [
        new Get(
            uriTemplate: '/admin/failed-messages',
            provider: FailedMessagesProvider::class,
            openapi: new Model\Operation(summary: 'List the failed messages, optionally filtered by message class', tags: ['Administration']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class FailedMessagesResource
{
    public int $total = 0;

    /** @var list<FailedMessageClassCountDto> */
    public array $classes = [];

    /** @var list<FailedMessageItemDto> */
    public array $messages = [];
}

class FailedMessageClassCountDto
{
    public function __construct(
        public string $messageClass = '',
        public int $count = 0,
    ) {
    }
}

class FailedMessageItemDto
{
    public function __construct(
        public string $id = '',
        public ?string $messageClass = null,
        public ?string $exceptionClass = null,
        public ?string $exceptionMessage = null,
        public ?string $failedAt = null,
        public ?int $retryCount = null,
    ) {
    }
}
