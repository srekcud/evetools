<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Entity\EveToken;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\ESI\CorporationService;
use App\State\Provider\Assets\CorpAssetVisibilityProvider;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CorpAssetVisibilityProvider::class)]
class CorpAssetVisibilityProviderTest extends TestCase
{
    private Security&Stub $security;
    private CorpAssetVisibilityRepository&Stub $visibilityRepository;
    private CachedAssetRepository&Stub $cachedAssetRepository;
    private CorporationService&MockObject $corporationService;
    private CorpAssetVisibilityProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->corporationService = $this->createMock(CorporationService::class);

        $this->provider = new CorpAssetVisibilityProvider(
            $this->security,
            $this->visibilityRepository,
            $this->cachedAssetRepository,
            $this->corporationService,
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
