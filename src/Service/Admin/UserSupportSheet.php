<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class UserSupportSheet
{
    /**
     * @param list<UserSupportCharacter> $characters
     * @param list<UserSupportSyncLine>  $syncs
     */
    public function __construct(
        public string $userId,
        public string $createdAt,
        public ?string $lastLoginAt,
        public array $characters,
        public array $syncs,
    ) {
    }
}
