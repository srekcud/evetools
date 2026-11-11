<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\CharacterRepairState;
use App\Repository\CharacterRepository;
use App\Service\Admin\CharacterRepairLister;
use App\Service\ESI\AuthenticationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-648')]
final class CharacterRepairListerTest extends TestCase
{
    private CharacterRepairLister $lister;

    protected function setUp(): void
    {
        $this->lister = new CharacterRepairLister($this->createStub(CharacterRepository::class));
    }

    public function testAnInvalidAuthorizationIsListedAsInvalidWithTheLastTokenUpdate(): void
    {
        $character = $this->characterOf('Mira Tolland', 1001, $this->completeScopes(), '2026-10-01T08:00:00+00:00');
        $character->markAuthInvalid();

        $entries = $this->lister->repairsAmong([$character]);

        self::assertCount(1, $entries);
        self::assertSame(CharacterRepairState::Invalid, $entries[0]->state);
        self::assertSame(1001, $entries[0]->eveCharacterId);
        self::assertSame('Mira Tolland', $entries[0]->characterName);
        self::assertSame('2026-10-01T08:00:00+00:00', $entries[0]->sinceAt);
        self::assertSame([], $entries[0]->missingScopes);
    }

    public function testAValidCharacterWithAllRequiredScopesIsNotListed(): void
    {
        $character = $this->characterOf('Dax Orrin', 1002, $this->completeScopes(), '2026-10-01T08:00:00+00:00');

        self::assertSame([], $this->lister->repairsAmong([$character]));
    }

    public function testAValidCharacterMissingScopesIsListedWithThemInRequiredOrder(): void
    {
        $required = AuthenticationService::getRequiredScopes();
        $scopes = array_values(array_diff($required, [$required[3], $required[1]]));
        $character = $this->characterOf('Dax Orrin', 1003, $scopes, '2026-10-02T08:00:00+00:00');

        $entries = $this->lister->repairsAmong([$character]);

        self::assertCount(1, $entries);
        self::assertSame(CharacterRepairState::MissingScopes, $entries[0]->state);
        self::assertSame([$required[1], $required[3]], $entries[0]->missingScopes);
    }

    public function testAnInvalidCharacterAlsoMissingScopesStaysInvalidAndKeepsTheirList(): void
    {
        $required = AuthenticationService::getRequiredScopes();
        $character = $this->characterOf('Mira Tolland', 1004, array_slice($required, 1), '2026-10-02T08:00:00+00:00');
        $character->markAuthInvalid();

        $entries = $this->lister->repairsAmong([$character]);

        self::assertSame(CharacterRepairState::Invalid, $entries[0]->state);
        self::assertSame([$required[0]], $entries[0]->missingScopes);
    }

    public function testAnExtraScopeOnTheTokenIsNeverListedAsMissing(): void
    {
        $character = $this->characterOf('Dax Orrin', 1005, [...$this->completeScopes(), 'esi-extra.unknown.v1'], '2026-10-02T08:00:00+00:00');

        self::assertSame([], $this->lister->repairsAmong([$character]));
    }

    public function testACharacterWithoutTokenIsNotListed(): void
    {
        $character = (new Character())->setEveCharacterId(1006)->setName('Tokenless');

        self::assertSame([], $this->lister->repairsAmong([$character]));
    }

    public function testTheOwnerAndTheLastLoginAreExposedWhenTheCharacterHasAUser(): void
    {
        $character = $this->characterOf('Mira Tolland', 1007, $this->completeScopes(), '2026-10-01T08:00:00+00:00');
        $character->markAuthInvalid();
        $userId = Uuid::v7();
        $user = $this->userOf($userId, new \DateTimeImmutable('2026-10-20T12:30:00+00:00'));
        $user->addCharacter($character);

        $entries = $this->lister->repairsAmong([$character]);

        self::assertSame($userId->toRfc4122(), $entries[0]->userId);
        self::assertSame('2026-10-20T12:30:00+00:00', $entries[0]->userLastLoginAt);
    }

    public function testAUserNeverLoggedInHasNoLastLoginInsteadOfADefaultDate(): void
    {
        $character = $this->characterOf('Mira Tolland', 1008, $this->completeScopes(), '2026-10-01T08:00:00+00:00');
        $character->markAuthInvalid();
        $this->userOf(Uuid::v7(), null)->addCharacter($character);

        $entries = $this->lister->repairsAmong([$character]);

        self::assertNull($entries[0]->userLastLoginAt);
    }

    public function testACharacterWithoutUserHasNoOwnerNorLastLogin(): void
    {
        $character = $this->characterOf('Mira Tolland', 1009, $this->completeScopes(), '2026-10-01T08:00:00+00:00');
        $character->markAuthInvalid();

        $entries = $this->lister->repairsAmong([$character]);

        self::assertNull($entries[0]->userId);
        self::assertNull($entries[0]->userLastLoginAt);
    }

    public function testInvalidComesBeforeMissingScopesThenTheOldestFirstThenByName(): void
    {
        $missing = array_slice(AuthenticationService::getRequiredScopes(), 1);
        $oldMissing = $this->characterOf('Old Missing', 1, $missing, '2026-09-01T00:00:00+00:00');
        $recentInvalid = $this->characterOf('Recent Invalid', 2, $this->completeScopes(), '2026-10-30T00:00:00+00:00');
        $recentInvalid->markAuthInvalid();
        $oldInvalid = $this->characterOf('Old Invalid', 3, $this->completeScopes(), '2026-09-15T00:00:00+00:00');
        $oldInvalid->markAuthInvalid();
        $tieB = $this->characterOf('Beta', 4, $missing, '2026-10-01T00:00:00+00:00');
        $tieA = $this->characterOf('Alpha', 5, $missing, '2026-10-01T00:00:00+00:00');

        $entries = $this->lister->repairsAmong([$tieB, $recentInvalid, $oldMissing, $tieA, $oldInvalid]);

        self::assertSame(
            ['Old Invalid', 'Recent Invalid', 'Old Missing', 'Alpha', 'Beta'],
            array_map(static fn ($entry): string => $entry->characterName, $entries),
        );
    }

    /**
     * @return list<string>
     */
    private function completeScopes(): array
    {
        return AuthenticationService::getRequiredScopes();
    }

    /**
     * @param list<string> $scopes
     */
    private function characterOf(string $name, int $eveCharacterId, array $scopes, string $tokenUpdatedAt): Character
    {
        $token = (new EveToken())
            ->setAccessToken('access')
            ->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $updatedAt = new \ReflectionProperty(EveToken::class, 'updatedAt');
        $updatedAt->setValue($token, new \DateTimeImmutable($tokenUpdatedAt));

        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $character->setEveToken($token);

        return $character;
    }

    private function userOf(Uuid $id, ?\DateTimeImmutable $lastLoginAt): User
    {
        $user = (new User())->setLastLoginAt($lastLoginAt);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
