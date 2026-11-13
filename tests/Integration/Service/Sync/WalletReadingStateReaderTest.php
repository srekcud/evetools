<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sync;

use App\ApiResource\Industry\WalletReadingResource;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\Sync\WalletReadingStateReader;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-916')]
final class WalletReadingStateReaderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    public function testStatesOfTheStoredCharactersAreReadFromTheDatabase(): void
    {
        $user = $this->createUser();
        $main = $this->createCharacterWithToken($user, 'Aria Solberg', [self::WALLET_SCOPE]);
        $user->setMainCharacter($main);
        $this->createCharacterWithToken($user, 'Brann Holt', ['esi-skills.read_skills.v1']);
        $this->createCharacter($user, 'Cora Venn');
        $this->createCharacterWithToken($user, 'Dorn Achar', [self::WALLET_SCOPE]);
        $this->em->persist(
            (new CharacterWalletReading())
                ->setCharacter($main)
                ->setReadAt(new \DateTimeImmutable('2026-11-13 20:04:10 UTC'))
                ->setNextReadAt(new \DateTimeImmutable('2026-11-13 21:04:00 UTC')),
        );
        $this->flushAndClear();

        $states = array_map(
            static fn (WalletReadingResource $state): array => [
                $state->characterName,
                $state->status,
                $state->readAt === null ? null : (new \DateTimeImmutable($state->readAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $state->nextReadAt === null ? null : (new \DateTimeImmutable($state->nextReadAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
            $this->reader()->forUser($this->em->find(User::class, $user->getId())),
        );
        $others = \array_slice($states, 1);
        usort($others, static fn (array $first, array $second): int => $first[0] <=> $second[0]);

        self::assertSame(['Aria Solberg', 'read', '2026-11-13 20:04:10', '2026-11-13 21:04:00'], $states[0] ?? null);
        self::assertSame(
            [
                ['Brann Holt', 'missing_scope', null, null],
                ['Cora Venn', 'no_token', null, null],
                ['Dorn Achar', 'never_read', null, null],
            ],
            $others,
        );
    }

    public function testStatesIssueTheSameQueriesForTwoOrFourCharactersOfTheUser(): void
    {
        $twoCharactersUserId = $this->createUserWithReadCharacters('Rhea Tallis', 2);
        $fourCharactersUserId = $this->createUserWithReadCharacters('Joss Varn', 4);

        [$twoCharactersQueries, $twoCharactersStates] = $this->queriesReadingTheStatesOf($twoCharactersUserId);
        [$fourCharactersQueries, $fourCharactersStates] = $this->queriesReadingTheStatesOf($fourCharactersUserId);

        self::assertSame([2, 4], [$twoCharactersStates, $fourCharactersStates]);
        self::assertCount(
            \count($twoCharactersQueries),
            $fourCharactersQueries,
            sprintf(
                "2 characters: %d queries; 4 characters: %d queries:\n%s",
                \count($twoCharactersQueries),
                \count($fourCharactersQueries),
                implode("\n", $fourCharactersQueries),
            ),
        );
    }

    private function reader(): WalletReadingStateReader
    {
        return new WalletReadingStateReader(self::getContainer()->get(CharacterWalletReadingRepository::class));
    }

    private function createUserWithReadCharacters(string $prefix, int $charactersCount): string
    {
        $user = $this->createUser();
        for ($index = 1; $index <= $charactersCount; ++$index) {
            $character = $this->createCharacterWithToken($user, sprintf('Pilot %d of %s', $index, $prefix), [self::WALLET_SCOPE]);
            $this->em->persist(
                (new CharacterWalletReading())
                    ->setCharacter($character)
                    ->setReadAt(new \DateTimeImmutable('2026-11-13 20:04:10 UTC'))
                    ->setNextReadAt(new \DateTimeImmutable('2026-11-13 21:04:00 UTC')),
            );
        }
        $this->flushAndClear();

        return (string) $user->getId();
    }

    private function queriesReadingTheStatesOf(string $userId): array
    {
        $user = $this->em->find(User::class, $userId);
        $states = [];
        $queries = $this->sqlExecutedDuring(function () use ($user, &$states): void {
            $states = $this->reader()->forUser($user);
        });

        return [$queries, \count($states)];
    }

    private function createCharacterWithToken(User $user, string $name, array $scopes): Character
    {
        $character = $this->createCharacter($user, $name);
        $token = (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('encrypted-refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $character->setEveToken($token);
        $this->em->persist($token);

        return $character;
    }
}
