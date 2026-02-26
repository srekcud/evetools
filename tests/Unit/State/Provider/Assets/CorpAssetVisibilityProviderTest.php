<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\State\Provider\Assets\CorpAssetVisibilityProvider;
use PHPUnit\Framework\Attributes\CoversClass;
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
    private CorpAssetVisibilityProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);

        $this->provider = new CorpAssetVisibilityProvider(
            $this->security,
            $this->visibilityRepository,
            $this->cachedAssetRepository,
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
