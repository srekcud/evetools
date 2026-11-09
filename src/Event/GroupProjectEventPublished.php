<?php

declare(strict_types=1);

namespace App\Event;

final readonly class GroupProjectEventPublished
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $projectId,
        public string $action,
        public array $data,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
