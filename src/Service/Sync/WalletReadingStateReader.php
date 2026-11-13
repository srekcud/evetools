<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\ApiResource\Industry\WalletReadingResource;
use App\Entity\User;
use App\Repository\CharacterWalletReadingRepository;

final readonly class WalletReadingStateReader
{
    public function __construct(
        private CharacterWalletReadingRepository $readingRepository,
    ) {
    }

    /**
     * @return list<WalletReadingResource>
     */
    public function forUser(User $user): array
    {
        return [];
    }
}
