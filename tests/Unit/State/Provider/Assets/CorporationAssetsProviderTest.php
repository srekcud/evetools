<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Provider\Assets\CorporationAssetsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CorporationAssetsProvider::class)]
class CorporationAssetsProviderTest extends TestCase
{
    private Security&Stub $security;
    private CachedAssetRepository&Stub $cachedAssetRepository;
    private CorpAssetVisibilityRepository&Stub $visibilityRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private RequestStack $requestStack;
    private CorporationAssetsProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->invTypeRepository->method('findByTypeIds')->willReturn([]);
        $this->requestStack = new RequestStack();

        $this->provider = new CorporationAssetsProvider(
            $this->security,
            $this->cachedAssetRepository,
            $this->visibilityRepository,
            $this->invTypeRepository,
            $this->requestStack,
        );
    }

    // ===========================================
    // Without visibility config (retrocompat)
    // ===========================================

    public function testWithoutConfigReturnsAllAssets(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);

        $asset = $this->createAssetStub();
        $this->cachedAssetRepository->method('findByCorporationId')->willReturn([$asset]);

        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        $this->assertSame(1, $result->total);
        $this->assertCount(1, $result->items);
    }

    public function testWithoutConfigAndDivisionFilterUsesLegacyQuery(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);

        $asset = $this->createAssetStub();
        $this->cachedAssetRepository->method('findByCorporationAndDivision')->willReturn([$asset]);

        $this->requestStack->push(new Request(['divisionName' => 'Materials']));

        $result = $this->provider->provide(new Get());

        $this->assertSame(1, $result->total);
    }

    // ===========================================
    // With visibility config
    // ===========================================

    public function testWithConfigFiltersToVisibleDivisions(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1, 3]);
        $visibility->setConfiguredBy($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);

        $asset = $this->createAssetStub();
        $this->cachedAssetRepository->method('findByCorporationAndDivisions')->willReturn([$asset]);

        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        $this->assertSame(1, $result->total);
    }

    public function testWithConfigAndDivisionFilterAppliesBothConstraints(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1, 3]);
        $visibility->setConfiguredBy($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);

        $asset = $this->createAssetStub();
        $this->cachedAssetRepository->method('findByCorporationDivisionNameAndFlags')->willReturn([$asset]);

        $this->requestStack->push(new Request(['divisionName' => 'Materials']));

        $result = $this->provider->provide(new Get());

        $this->assertSame(1, $result->total);
    }

    public function testWithEmptyVisibleDivisionsReturnsNoAssets(): void
    {
        $user = $this->createUserWithCharacter();
        $this->security->method('getUser')->willReturn($user);

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([]);
        $visibility->setConfiguredBy($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);

        $this->cachedAssetRepository->method('findByCorporationAndDivisions')->willReturn([]);

        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->items);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithCharacter(): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getCorporationId')->willReturn(98000001);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }

    private function createAssetStub(): CachedAsset
    {
        $asset = new CachedAsset();
        $asset->setItemId(1001);
        $asset->setTypeId(34);
        $asset->setTypeName('Tritanium');
        $asset->setQuantity(1000);
        $asset->setLocationId(60003760);
        $asset->setLocationName('Jita IV - Moon 4');
        $asset->setLocationType('station');
        $asset->setLocationFlag('CorpSAG1');

        return $asset;
    }
}
