<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\AuthStatus;
use App\Repository\CharacterRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Issue #28: freezes CharacterRepository::findStructureMarketRequester(int $structureId, bool $isDefaultStructure): ?Character,
 * the character whose token syncs a structure market. It must belong to a user who requested the structure
 * (preferred market structure; for the default structure, users without preference too) and carry the
 * structure market scope. Among several candidates, the most recently logged-in user wins.
 */
final class CharacterRepositoryStructureMarketRequesterTest extends IntegrationTestCase
{
    private const int DEFAULT_STRUCTURE_ID = 1_035_466_617_946;
    private const int PREFERRED_STRUCTURE_ID = 1_046_664_001_931;
    private const int OTHER_STRUCTURE_ID = 1_048_000_000_001;
    private const string STRUCTURE_MARKET_SCOPE = 'esi-markets.structure_markets.v1';
    private const string UNRELATED_SCOPE = 'esi-assets.read_assets.v1';

    private CharacterRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CharacterRepository::class);
    }

    public function testReturnsTheCharacterOfTheUserWhoPrefersTheStructure(): void
    {
        $this->createPilot('Other Structure Pilot', $this->createRequester(self::OTHER_STRUCTURE_ID));
        $this->createPilot('Requester Pilot', $this->createRequester(self::PREFERRED_STRUCTURE_ID));
        $this->flushAndClear();

        self::assertSame('Requester Pilot', $this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testReturnsNullWhenNoUserPrefersTheStructure(): void
    {
        $this->createPilot('Other Structure Pilot', $this->createRequester(self::OTHER_STRUCTURE_ID));
        $this->createPilot('No Preference Pilot', $this->createRequester(null));
        $this->flushAndClear();

        self::assertNull($this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testIgnoresCharactersWhoseTokenLacksTheStructureMarketScope(): void
    {
        $this->createPilot('No Scope Pilot', $this->createRequester(self::PREFERRED_STRUCTURE_ID), [self::UNRELATED_SCOPE]);
        $this->flushAndClear();

        self::assertNull($this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testPicksTheScopedCharacterAmongTheRequesterCharacters(): void
    {
        $requester = $this->createRequester(self::PREFERRED_STRUCTURE_ID);
        $this->createPilot('Alt Without Scope', $requester, [self::UNRELATED_SCOPE]);
        $this->createPilot('Main With Scope', $requester);
        $this->createPilot('Alt Without Token', $requester, null);
        $this->flushAndClear();

        self::assertSame('Main With Scope', $this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testIgnoresCharactersWithoutToken(): void
    {
        $this->createPilot('Tokenless Pilot', $this->createRequester(self::PREFERRED_STRUCTURE_ID), null);
        $this->flushAndClear();

        self::assertNull($this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testIgnoresUsersWhoseAuthenticationIsInvalid(): void
    {
        $requester = $this->createRequester(self::PREFERRED_STRUCTURE_ID)->setAuthStatus(AuthStatus::Invalid);
        $this->createPilot('Invalid Auth Pilot', $requester);
        $this->flushAndClear();

        self::assertNull($this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testUsersWithoutPreferenceRequestTheDefaultStructure(): void
    {
        $this->createPilot('No Preference Pilot', $this->createRequester(null));
        $this->flushAndClear();

        self::assertSame('No Preference Pilot', $this->requesterNameFor(self::DEFAULT_STRUCTURE_ID, isDefaultStructure: true));
    }

    public function testUsersWithoutPreferenceDoNotRequestANonDefaultStructure(): void
    {
        $this->createPilot('No Preference Pilot', $this->createRequester(null));
        $this->flushAndClear();

        self::assertNull($this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    public function testUsersPreferringAnotherStructureDoNotRequestTheDefaultStructure(): void
    {
        $this->createPilot('Other Structure Pilot', $this->createRequester(self::OTHER_STRUCTURE_ID));
        $this->flushAndClear();

        self::assertNull($this->requesterNameFor(self::DEFAULT_STRUCTURE_ID, isDefaultStructure: true));
    }

    public function testPrefersTheMostRecentlyLoggedInRequester(): void
    {
        $this->createPilot('Never Logged In', $this->createRequester(self::PREFERRED_STRUCTURE_ID, lastLoginAt: null));
        $this->createPilot('Logged In Oct 1', $this->createRequester(self::PREFERRED_STRUCTURE_ID, lastLoginAt: '2026-10-01 12:00:00'));
        $this->createPilot('Logged In Oct 5', $this->createRequester(self::PREFERRED_STRUCTURE_ID, lastLoginAt: '2026-10-05 12:00:00'));
        $this->createPilot('Logged In Oct 3', $this->createRequester(self::PREFERRED_STRUCTURE_ID, lastLoginAt: '2026-10-03 12:00:00'));
        $this->flushAndClear();

        self::assertSame('Logged In Oct 5', $this->requesterNameFor(self::PREFERRED_STRUCTURE_ID));
    }

    private function requesterNameFor(int $structureId, bool $isDefaultStructure = false): ?string
    {
        return $this->repository->findStructureMarketRequester($structureId, $isDefaultStructure)?->getName();
    }

    private function createRequester(?int $preferredMarketStructureId, ?string $lastLoginAt = '2026-10-01 12:00:00'): User
    {
        return $this->createUser()
            ->setPreferredMarketStructureId($preferredMarketStructureId)
            ->setLastLoginAt($lastLoginAt === null ? null : new \DateTimeImmutable($lastLoginAt));
    }

    /** @param list<string>|null $scopes null = character without token */
    private function createPilot(string $name, User $user, ?array $scopes = [self::STRUCTURE_MARKET_SCOPE]): Character
    {
        $character = $this->createCharacter($user, $name);

        if ($scopes !== null) {
            $character->setEveToken(
                (new EveToken())
                    ->setAccessToken('access-' . $name)
                    ->setRefreshTokenEncrypted('refresh-' . $name)
                    ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
                    ->setScopes($scopes),
            );
        }

        return $character;
    }
}
