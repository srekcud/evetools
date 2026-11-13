<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\ApiResource\Industry\WalletReadingResource;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\User;
use App\Repository\CharacterWalletReadingRepository;

final readonly class WalletReadingStateReader
{
    public const string STATUS_NO_TOKEN = 'no_token';
    public const string STATUS_MISSING_SCOPE = 'missing_scope';
    public const string STATUS_NEVER_READ = 'never_read';
    public const string STATUS_READ = 'read';

    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    public function __construct(
        private CharacterWalletReadingRepository $readingRepository,
    ) {
    }

    /**
     * @return list<WalletReadingResource>
     */
    public function forUser(User $user): array
    {
        $characters = array_values($user->getCharacters()->toArray());
        if ($characters === []) {
            return [];
        }

        $readingsByCharacter = [];
        foreach ($this->readingRepository->findByCharacters($characters) as $reading) {
            $readingsByCharacter[spl_object_id($reading->getCharacter())] = $reading;
        }

        return array_map(
            fn (Character $character): WalletReadingResource => $this->stateOf($character, $readingsByCharacter[spl_object_id($character)] ?? null),
            $this->mainFirst($characters, $user->getMainCharacter()),
        );
    }

    /**
     * @param list<Character> $characters
     * @return list<Character>
     */
    private function mainFirst(array $characters, ?Character $main): array
    {
        if ($main === null || !in_array($main, $characters, true)) {
            return $characters;
        }

        return [$main, ...array_values(array_filter($characters, static fn (Character $character): bool => $character !== $main))];
    }

    private function stateOf(Character $character, ?CharacterWalletReading $reading): WalletReadingResource
    {
        $state = new WalletReadingResource();
        $state->characterName = $character->getName();
        $state->status = $this->statusOf($character, $reading);

        if ($state->status === self::STATUS_READ) {
            $state->readAt = $reading?->getReadAt()?->format(\DateTimeInterface::ATOM);
            $state->nextReadAt = $reading?->getNextReadAt()->format(\DateTimeInterface::ATOM);
        }

        return $state;
    }

    private function statusOf(Character $character, ?CharacterWalletReading $reading): string
    {
        $token = $character->getEveToken();

        return match (true) {
            $token === null => self::STATUS_NO_TOKEN,
            !$token->hasScope(self::WALLET_SCOPE) => self::STATUS_MISSING_SCOPE,
            $reading?->getReadAt() === null => self::STATUS_NEVER_READ,
            default => self::STATUS_READ,
        };
    }
}
