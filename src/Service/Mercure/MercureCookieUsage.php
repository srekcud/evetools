<?php

declare(strict_types=1);

namespace App\Service\Mercure;

final readonly class MercureCookieUsage
{
    public function __construct(
        public string $userId,
        public ?string $mainCharacterName,
        public int $cookieBytes,
        public int $topicCount,
    ) {
    }
}
