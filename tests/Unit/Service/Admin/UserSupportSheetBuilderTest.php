<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\CharacterTokenState;
use App\Service\Admin\UserLastDataReader;
use App\Service\Admin\UserSupportSheetBuilder;
use App\Service\Admin\UserTriggerableSyncs;
use App\Service\ESI\AuthenticationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UserSupportSheetBuilder::class)]
#[Group('issue-647')]
final class UserSupportSheetBuilderTest extends TestCase
{
    private const USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';

    /** @var array<string, ?\DateTimeImmutable> */
    private array $lastDataByType = [];

    protected function setUp(): void
    {
        $this->lastDataByType = [];
    }

    public function testTheSheetCarriesTheUserIdentityAndDates(): void
    {
        $user = $this->userWith([$this->characterOf('Mira', 1001)]);
        $user->setLastLoginAt(new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));

        $sheet = $this->build($user);

        self::assertSame(self::USER_ID, $sheet->userId);
        self::assertSame($user->getCreatedAt()->format(\DateTimeInterface::ATOM), $sheet->createdAt);
        self::assertSame('2026-11-01T10:00:00+00:00', $sheet->lastLoginAt);
    }

    public function testAUserWhoNeverLoggedInHasNoLastLogin(): void
    {
        self::assertNull($this->build($this->userWith([$this->characterOf('Mira', 1001)]))->lastLoginAt);
    }

    public function testTheMainCharacterComesFirstThenTheOthersByName(): void
    {
        $zed = $this->characterOf('Zed', 1003);
        $amy = $this->characterOf('Amy', 1002);
        $main = $this->characterOf('Mira', 1001);
        $user = $this->userWith([$zed, $amy, $main], $main);

        $sheet = $this->build($user);

        self::assertSame(['Mira', 'Amy', 'Zed'], array_map(static fn ($character): string => $character->name, $sheet->characters));
        self::assertSame([true, false, false], array_map(static fn ($character): bool => $character->isMain, $sheet->characters));
    }

    public function testAUserWithoutMainCharacterListsTheCharactersByName(): void
    {
        $sheet = $this->build($this->userWith([$this->characterOf('Zed', 1003), $this->characterOf('Amy', 1002)]));

        self::assertSame(['Amy', 'Zed'], array_map(static fn ($character): string => $character->name, $sheet->characters));
        self::assertSame([false, false], array_map(static fn ($character): bool => $character->isMain, $sheet->characters));
    }

    public function testACharacterWithoutTokenHasTheNoTokenState(): void
    {
        $character = $this->build($this->userWith([$this->characterOf('Mira', 1001, token: false)]))->characters[0];

        self::assertSame(CharacterTokenState::NoToken, $character->tokenState);
        self::assertNull($character->accessTokenExpiresAt);
        self::assertSame([], $character->missingScopes);
    }

    public function testARevokedCharacterIsInvalidEvenWithAnExpiredAccessToken(): void
    {
        $character = $this->characterOf('Mira', 1001, expiresAt: '-1 hour');
        $character->markAuthInvalid();

        self::assertSame(CharacterTokenState::Invalid, $this->build($this->userWith([$character]))->characters[0]->tokenState);
    }

    public function testAValidCharacterWhoseAccessTokenExpiresInTwoMinutesIsExpiring(): void
    {
        $character = $this->characterOf('Mira', 1001, expiresAt: '+2 minutes');

        self::assertSame(CharacterTokenState::Expiring, $this->build($this->userWith([$character]))->characters[0]->tokenState);
    }

    public function testAValidCharacterWithAFreshAccessTokenIsValid(): void
    {
        $character = $this->characterOf('Mira', 1001, expiresAt: '+20 minutes');

        $entry = $this->build($this->userWith([$character]))->characters[0];

        self::assertSame(CharacterTokenState::Valid, $entry->tokenState);
        self::assertNotNull($entry->accessTokenExpiresAt);
    }

    public function testTheMissingScopesAreTheRequiredScopesTheTokenLacks(): void
    {
        $requiredScopes = AuthenticationService::getRequiredScopes();
        $character = $this->characterOf('Mira', 1001, scopes: array_slice($requiredScopes, 1));

        $entry = $this->build($this->userWith([$character]))->characters[0];

        self::assertSame(CharacterTokenState::Valid, $entry->tokenState);
        self::assertSame([$requiredScopes[0]], $entry->missingScopes);
    }

    public function testTheCharacterLastSyncIsExposedOrNull(): void
    {
        $synced = $this->characterOf('Amy', 1002);
        $synced->setLastSyncAt(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'));
        $never = $this->characterOf('Zed', 1003);

        $entries = $this->build($this->userWith([$synced, $never]))->characters;

        self::assertSame('2026-11-02T08:00:00+00:00', $entries[0]->lastSyncAt);
        self::assertNull($entries[1]->lastSyncAt);
    }

    public function testTheSyncLinesFollowTheDisplayOrderWithTheirLastDataAndTriggerability(): void
    {
        $this->lastDataByType = [
            'industry' => new \DateTimeImmutable('2026-11-03T09:00:00+00:00'),
            'blueprints' => null,
            'assets' => new \DateTimeImmutable('2026-11-04T09:00:00+00:00'),
        ];

        $syncs = $this->build($this->userWith([$this->characterOf('Mira', 1001)]))->syncs;

        self::assertSame(UserTriggerableSyncs::TYPES, array_map(static fn ($line): string => $line->type, $syncs));
        $byType = [];
        foreach ($syncs as $line) {
            $byType[$line->type] = $line;
        }
        self::assertSame('2026-11-03T09:00:00+00:00', $byType['industry']->lastDataAt);
        self::assertTrue($byType['industry']->triggerable);
        self::assertNull($byType['blueprints']->lastDataAt);
        self::assertNull($byType['wallet']->lastDataAt);
        self::assertSame('2026-11-04T09:00:00+00:00', $byType['assets']->lastDataAt);
        self::assertFalse($byType['assets']->triggerable);
    }

    private function build(User $user): \App\Service\Admin\UserSupportSheet
    {
        $reader = $this->createStub(UserLastDataReader::class);
        $reader->method('lastDataByType')->willReturnCallback(fn (): array => $this->lastDataByType);

        return (new UserSupportSheetBuilder($reader))->build($user);
    }

    /**
     * @param list<Character> $characters
     */
    private function userWith(array $characters, ?Character $main = null): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString(self::USER_ID));
        foreach ($characters as $character) {
            $user->addCharacter($character);
        }
        $user->setMainCharacter($main);

        return $user;
    }

    /**
     * @param list<string>|null $scopes
     */
    private function characterOf(string $name, int $eveCharacterId, bool $token = true, string $expiresAt = '+20 minutes', ?array $scopes = null): Character
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        if ($token) {
            $character->setEveToken((new EveToken())
                ->setAccessToken('access')
                ->setRefreshTokenEncrypted('refresh')
                ->setAccessTokenExpiresAt(new \DateTimeImmutable($expiresAt))
                ->setScopes($scopes ?? AuthenticationService::getRequiredScopes()));
        }

        return $character;
    }
}
