<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncCharacterWalletTransactions;
use App\Repository\CharacterWalletReadingRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ContributorWalletRefresher
{
    public function __construct(
        private readonly CharacterWalletReadingRepository $readingRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function request(User $user): WalletRefreshRequest
    {
        $walletCharacters = WalletReadingDueness::walletCharactersById(
            array_filter($user->getCharacters()->toArray(), static fn (Character $character): bool => $character->isAuthValid()),
        );
        $readings = $this->readingRepository->findByCharacters(array_values($walletCharacters));
        $notDueCharacters = WalletReadingDueness::notDueAmong($walletCharacters, $readings, $this->clock->now());
        $dueCharacters = array_diff_key($walletCharacters, $notDueCharacters);

        foreach (array_keys($dueCharacters) as $characterId) {
            $this->messageBus->dispatch(new SyncCharacterWalletTransactions($characterId));
        }

        return new WalletRefreshRequest(\count($dueCharacters), \count($notDueCharacters));
    }
}
