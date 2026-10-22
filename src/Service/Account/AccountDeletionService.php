<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Service\ESI\EveSsoTokenRevoker;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EveSsoTokenRevoker $tokenRevoker,
        private readonly TokenManager $tokenManager,
        private readonly TagAwareAdapterInterface $esiCache,
    ) {
    }

    public function delete(User $user): void
    {
    }
}
