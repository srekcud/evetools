<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Repository\CharacterRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Scheduled syncs (TriggerAssetsSyncHandler, TriggerStructureMarketSyncHandler, and SyncIndustryJobs /
 * SyncWalletTransactions / SyncPlanetaryColonies via findActiveWithValidTokens) only process the characters
 * returned here: a user whose EVE auth was marked invalid must drop out of them (issue #13).
 */
final class CharacterRepositoryTest extends IntegrationTestCase
{
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

    public function testFindWithValidTokensSkipsCharactersOfUsersWhoseAuthIsInvalid(): void
    {
        $this->createCharacterWithToken('Valid Pilot');
        $revoked = $this->createCharacterWithToken('Revoked Pilot');
        $revoked->getUser()->markAuthInvalid();
        $this->flushAndClear();

        self::assertSame(['Valid Pilot'], $this->namesOf($this->repository->findWithValidTokens()));
    }

    public function testFindWithValidTokensSkipsCharactersWithoutToken(): void
    {
        $this->createCharacterWithToken('Valid Pilot');
        $this->createCharacter(name: 'Tokenless Pilot');
        $this->flushAndClear();

        self::assertSame(['Valid Pilot'], $this->namesOf($this->repository->findWithValidTokens()));
    }

    public function testFindActiveWithValidTokensSkipsRecentlyActiveUsersWhoseAuthIsInvalid(): void
    {
        $this->createCharacterWithToken('Valid Pilot')->getUser()->updateLastLogin();
        $revoked = $this->createCharacterWithToken('Revoked Pilot');
        $revoked->getUser()->updateLastLogin()->markAuthInvalid();
        $this->flushAndClear();

        self::assertSame(['Valid Pilot'], $this->namesOf($this->repository->findActiveWithValidTokens()));
    }

    private function createCharacterWithToken(string $name): Character
    {
        $character = $this->createCharacter(name: $name);
        $token = (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('encrypted-refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'));
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
