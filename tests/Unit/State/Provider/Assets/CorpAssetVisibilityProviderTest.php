<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\UserRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationService;
use App\Tests\Unit\Service\ESI\BuildsCorporationDirectorAccess;
use App\State\Provider\Assets\CorpAssetVisibilityProvider;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CorpAssetVisibilityProvider::class)]
#[Group('issue-358')]
class CorpAssetVisibilityProviderTest extends TestCase
{
    use BuildsCorporationDirectorAccess;

    private const string ALICE_ID = '0199a1f0-0000-7000-8000-000000358001';
    private const string BOB_ID = '0199a1f0-0000-7000-8000-000000358002';

    private Security&Stub $security;
    private CorpAssetVisibilityRepository&Stub $visibilityRepository;
    private CachedAssetRepository&Stub $cachedAssetRepository;
    private CorporationService&MockObject $corporationService;
    private CharacterService&Stub $characterService;
    private UserRepository&Stub $userRepository;
    private CorpAssetVisibilityProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->corporationService = $this->createMock(CorporationService::class);
        $this->characterService = $this->createStub(CharacterService::class);
        $this->userRepository = $this->createStub(UserRepository::class);

        $this->provider = new CorpAssetVisibilityProvider(
            $this->security,
            $this->visibilityRepository,
            $this->cachedAssetRepository,
            $this->corporationService,
            $this->characterService,
            $this->corporationDirectorAccess(),
            $this->userRepository,
        );
    }

    // ===========================================
    // Without config (defaults to all divisions visible)
    // ===========================================

    public function testWithoutConfigDefaultsToAllDivisionsVisible(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([
            1 => 'Minerals',
            2 => 'Fuel',
        ]);

        $result = $this->provider->provide(new Get());

        $this->assertInstanceOf(CorpAssetVisibilityResource::class, $result);
        $this->assertSame([1, 2], $result->visibleDivisions);
        $this->assertFalse($result->isDirector);
        $this->assertNull($result->configuredByName);
        $this->assertNull($result->updatedAt);
        $this->assertSame([1 => 'Minerals', 2 => 'Fuel'], $result->allDivisions);
        $this->assertTrue($result->shareBlueprintsOfHiddenDivisions);
    }

    // ===========================================
    // With config
    // ===========================================

    public function testWithConfigReturnsConfiguredDivisions(): void
    {
        $configUser = $this->createUserWithCharacter('ConfigAdmin');
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1, 3]);
        $visibility->setShareBlueprintsOfHiddenDivisions(false);
        $visibility->setConfiguredBy($configUser);
        $visibility->setUpdatedAt(new \DateTimeImmutable('2026-01-15T12:00:00Z'));

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([
            1 => 'Materials',
            2 => 'Ships',
            3 => 'Ammo',
        ]);

        $result = $this->provider->provide(new Get());

        $this->assertSame([1, 3], $result->visibleDivisions);
        $this->assertFalse($result->isDirector);
        $this->assertSame('ConfigAdmin', $result->configuredByName);
        $this->assertNotNull($result->updatedAt);
        $this->assertSame([1 => 'Materials', 2 => 'Ships', 3 => 'Ammo'], $result->allDivisions);
        $this->assertFalse($result->shareBlueprintsOfHiddenDivisions);
    }

    // ===========================================
    // ESI fallback when no cached assets
    // ===========================================

    public function testFallsBackToEsiWhenNoCachedAssetsAndVisibilityExists(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        // Director character with token in the same corp
        $directorToken = $this->createStub(EveToken::class);
        $directorCharacter = $this->createStub(Character::class);
        $directorCharacter->method('getCorporationId')->willReturn(98000001);
        $directorCharacter->method('getEveToken')->willReturn($directorToken);

        $directorUser = $this->createStub(User::class);
        $directorUser->method('getCharacters')->willReturn(new ArrayCollection([$directorCharacter]));

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1, 2]);
        $visibility->setConfiguredBy($directorUser);

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([]);

        $esiDivisions = [1 => 'Hangar 1', 2 => 'Hangar 2', 3 => 'Hangar 3'];
        $this->corporationService->expects($this->once())
            ->method('getDivisions')
            ->with($directorCharacter)
            ->willReturn($esiDivisions);

        $result = $this->provider->provide(new Get());

        $this->assertSame([1, 2], $result->visibleDivisions);
        $this->assertSame($esiDivisions, $result->allDivisions);
    }

    public function testEsiFallbackFailurePropagatesInsteadOfShowingEmptyDivisionList(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $directorCharacter = $this->createStub(Character::class);
        $directorCharacter->method('getCorporationId')->willReturn(98000001);
        $directorCharacter->method('getEveToken')->willReturn($this->createStub(EveToken::class));

        $directorUser = $this->createStub(User::class);
        $directorUser->method('getCharacters')->willReturn(new ArrayCollection([$directorCharacter]));

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1, 2]);
        $visibility->setConfiguredBy($directorUser);

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([]);

        $esiFailure = EsiApiException::fromResponse(503, 'ESI request failed', '/corporations/98000001/divisions/');
        $this->corporationService->expects($this->once())->method('getDivisions')->willThrowException($esiFailure);

        // EsiAuthFailureListener turns this exception into an HTTP 503 ESI_API_ERROR response
        $this->expectExceptionObject($esiFailure);

        $this->provider->provide(new Get());
    }

    public function testNoFallbackWhenCachedAssetsExist(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $directorUser = $this->createStub(User::class);

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1]);
        $visibility->setConfiguredBy($directorUser);

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([1 => 'Minerals']);

        // ESI should NOT be called
        $this->corporationService->expects($this->never())->method('getDivisions');

        $result = $this->provider->provide(new Get());

        $this->assertSame([1 => 'Minerals'], $result->allDivisions);
    }

    public function testNoFallbackWhenNoVisibilityConfig(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([]);

        // ESI should NOT be called
        $this->corporationService->expects($this->never())->method('getDivisions');

        $result = $this->provider->provide(new Get());

        $this->assertSame([], $result->allDivisions);
    }

    // ===========================================
    // Visibility per player (issue #358)
    // ===========================================

    public function testMainCharacterWithTheDirectorRoleMakesTheUserADirector(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([1 => 'Minerals']);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);

        $result = $this->provider->provide(new Get());

        $this->assertTrue($result->isDirector);
    }

    public function testPlayersOfTheCorporationAreListedForADirectorOnly(): void
    {
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([1 => 'Minerals']);
        $this->userRepository->method('findPlayersOfCorporation')->willReturnCallback(
            fn (int $corporationId): array => $corporationId === 98000001
                ? [$this->createUserWithId(self::ALICE_ID, 'Alice Voss'), $this->createUserWithId(self::BOB_ID, 'Bob Tamm')]
                : [],
        );
        $director = $this->createUserWithCharacter('Director');
        $member = $this->createUserWithCharacter('Member');
        $this->characterService->method('canReadCorporationAssets')->willReturnCallback(
            static fn (Character $character): bool => $character === $director->getMainCharacter(),
        );
        $this->security->method('getUser')->willReturn($director, $member);

        $asDirector = $this->provider->provide(new Get());
        $asMember = $this->provider->provide(new Get());

        $this->assertSame(
            [['id' => self::ALICE_ID, 'name' => 'Alice Voss'], ['id' => self::BOB_ID, 'name' => 'Bob Tamm']],
            $asDirector->players,
        );
        $this->assertSame([], $asMember->players);
    }

    public function testAccessibleDivisionsAreTheOnesVisibleToTheCurrentUser(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithId(self::ALICE_ID, 'Alice Voss'));
        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([2]);
        $visibility->setRestrictedDivisions([1 => [self::ALICE_ID], 4 => [self::BOB_ID]]);
        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([1 => 'Minerals', 2 => 'Fuel', 4 => 'Ammo']);

        $result = $this->provider->provide(new Get());

        $this->assertSame([1, 2], $result->accessibleDivisions);
        $this->assertSame([2], $result->visibleDivisions);
    }

    public function testDirectorConnectedWhenACharacterOfTheCorporationHoldsTheDirectorRole(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([1 => 'Minerals']);
        $this->corporationMember(98000001, ['Director']);

        $this->assertTrue($this->provider->provide(new Get())->directorConnected);
    }

    #[Group('issue-733')]
    public function testDirectorIsNotConnectedWhenTheCharactersOfTheCorporationHoldNoDirectorRole(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([1 => 'Minerals']);
        $this->corporationMember(98000001, []);
        $this->corporationMember(98000001, ['Accountant']);

        $this->assertFalse($this->provider->provide(new Get())->directorConnected);
    }

    public function testRestrictedDivisionsAreReturnedWithTheNamesOfTheirPlayers(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setRestrictedDivisions([4 => [self::BOB_ID, self::ALICE_ID]]);
        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $this->cachedAssetRepository->method('findDistinctDivisions')->willReturn([4 => 'Ammo']);
        $this->userRepository->method('findPlayersOfCorporation')->willReturn([
            $this->createUserWithId(self::ALICE_ID, 'Alice Voss'),
            $this->createUserWithId(self::BOB_ID, 'Bob Tamm'),
        ]);

        $result = $this->provider->provide(new Get());

        $this->assertSame(
            [4 => [['id' => self::BOB_ID, 'name' => 'Bob Tamm'], ['id' => self::ALICE_ID, 'name' => 'Alice Voss']]],
            $result->restrictedDivisions,
        );
    }

    // ===========================================
    // Auth errors
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new Get());
    }

    public function testThrowsAccessDeniedWhenNoMainCharacter(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);
        $this->security->method('getUser')->willReturn($user);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('No main character set');

        $this->provider->provide(new Get());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithId(string $userId, string $characterName): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getCorporationId')->willReturn(98000001);
        $character->method('getName')->willReturn($characterName);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString($userId));
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }

    private function createUserWithCharacter(string $characterName = 'TestPilot'): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getCorporationId')->willReturn(98000001);
        $character->method('getName')->willReturn($characterName);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }
}
