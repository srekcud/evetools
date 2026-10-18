<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Tests\Integration\IntegrationTestCase;

final class CharacterRepositoryTest extends IntegrationTestCase
{
    private const string CORP_ASSETS_SCOPE = 'esi-assets.read_corporation_assets.v1';
    private const int REVOKED_DIRECTOR_CORPORATION_ID = 98_086_086;

    private CharacterRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CharacterRepository::class);
    }

    public function testFindWithValidTokensKeepsCharactersOfUsersWithValidAuth(): void
    {
        $this->createCharacterWithToken('Valid Pilot');
        $this->flushAndClear();

        self::assertSame(['Valid Pilot'], $this->namesOf($this->repository->findWithValidTokens()));
    }

    public function testFindWithValidTokensSkipsTheRevokedAltAndKeepsTheMainOfTheSameUser(): void
    {
        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        $user->setMainCharacter($this->createCharacterWithToken('Main Pilot', $user));
        $this->createCharacterWithToken('Revoked Alt Pilot', $user)->markAuthInvalid();
        $this->flushAndClear();

        self::assertSame(['Main Pilot'], $this->namesOf($this->repository->findWithValidTokens()));
    }

    public function testFindWithValidTokensSkipsCharactersWithoutToken(): void
    {
        $this->createCharacterWithToken('Valid Pilot');
        $this->createCharacter(name: 'Tokenless Pilot');
        $this->flushAndClear();

        self::assertSame(['Valid Pilot'], $this->namesOf($this->repository->findWithValidTokens()));
    }

    public function testFindActiveWithValidTokensSkipsTheRevokedAltAndKeepsTheMainOfTheSameUser(): void
    {
        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        $user->setMainCharacter($this->createCharacterWithToken('Main Pilot', $user));
        $this->createCharacterWithToken('Revoked Alt Pilot', $user)->markAuthInvalid();
        $this->flushAndClear();

        self::assertSame(['Main Pilot'], $this->namesOf($this->repository->findActiveWithValidTokens()));
    }

    public function testFindActiveWithValidTokensSkipsEveryCharacterOfAUserWhoseCharactersAreAllRevoked(): void
    {
        $this->createCharacterWithToken('Valid Pilot')->getUser()->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-1 day'));
        $user->setMainCharacter($this->createCharacterWithToken('Revoked Main Pilot', $user)->markAuthInvalid());
        $this->createCharacterWithToken('Revoked Alt Pilot', $user)->markAuthInvalid();
        $this->flushAndClear();

        self::assertSame(['Valid Pilot'], $this->namesOf($this->repository->findActiveWithValidTokens()));
    }

    public function testFindWithCorpAssetsAccessSkipsTheRevokedDirectorAndPicksTheValidCorpMember(): void
    {
        $directorUser = $this->createUser();
        $this->createCharacterWithToken('Revoked Director Pilot', $directorUser, [self::CORP_ASSETS_SCOPE], self::REVOKED_DIRECTOR_CORPORATION_ID)->markAuthInvalid();
        $this->createCharacterWithToken('Member Pilot', null, [self::CORP_ASSETS_SCOPE], self::REVOKED_DIRECTOR_CORPORATION_ID);
        $this->flushAndClear();

        self::assertSame('Member Pilot', $this->repository->findWithCorpAssetsAccess(self::REVOKED_DIRECTOR_CORPORATION_ID)?->getName());
    }

    public function testFindWithCorpAssetsAccessReturnsNullWhenTheOnlyScopedCharacterIsRevoked(): void
    {
        $this->createCharacterWithToken('Revoked Director Pilot', null, [self::CORP_ASSETS_SCOPE], self::REVOKED_DIRECTOR_CORPORATION_ID)->markAuthInvalid();
        $this->createCharacterWithToken('Unscoped Member Pilot', null, ['esi-assets.read_assets.v1'], self::REVOKED_DIRECTOR_CORPORATION_ID);
        $this->flushAndClear();

        self::assertNull($this->repository->findWithCorpAssetsAccess(self::REVOKED_DIRECTOR_CORPORATION_ID)?->getName());
    }

    private function createCharacterWithToken(string $name, ?User $user = null, array $scopes = [], ?int $corporationId = null): Character
    {
        $character = $this->createCharacter($user, $name);
        if ($corporationId !== null) {
            $character->setCorporationId($corporationId);
        }
        $token = (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('encrypted-refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $character->setEveToken($token);
        $this->em->persist($token);

        return $character;
    }

    /**
     * Only the characters created by the test: the test database may hold other rows.
     *
     * @param Character[] $characters
     * @return list<string>
     */
    private function namesOf(array $characters): array
    {
        $names = array_map(static fn (Character $c): string => $c->getName(), $characters);
        $names = array_values(array_filter($names, static fn (string $n): bool => str_ends_with($n, ' Pilot')));
        sort($names);

        return $names;
    }
}
