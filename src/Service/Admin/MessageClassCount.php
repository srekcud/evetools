<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class MessageClassCount
{
    public function __construct(
        public string $messageClass,
        public int $count,
    ) {
    }
}
