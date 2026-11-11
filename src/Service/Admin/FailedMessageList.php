<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class FailedMessageList
{
    /**
     * @param list<MessageClassCount> $classes
     * @param list<FailedMessage>     $messages
     */
    public function __construct(
        public int $total,
        public array $classes,
        public array $messages,
    ) {
    }
}
