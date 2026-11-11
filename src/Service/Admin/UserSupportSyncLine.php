<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class UserSupportSyncLine
{
    public function __construct(
        public string $type,
        public ?string $lastDataAt,
        public bool $triggerable,
    ) {
    }
}
