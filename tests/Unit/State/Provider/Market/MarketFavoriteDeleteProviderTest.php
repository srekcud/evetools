<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\Delete;
use App\Entity\MarketFavorite;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Service\TypeNameResolver;
use App\State\Provider\Market\MarketFavoriteDeleteProvider;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(MarketFavoriteDeleteProvider::class)]
class MarketFavoriteDeleteProviderTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int TRITANIUM_TYPE_ID = 34;
    private const string TRITANIUM_NAME = 'Tritanium';

    public function testDeletedFavoriteCarriesItsSdeTypeName(): void
    {
        $resource = $this->providerForFavoriteOf(self::TRITANIUM_TYPE_ID)
            ->provide(new Delete(), ['typeId' => (string) self::TRITANIUM_TYPE_ID]);

        $this->assertSame(self::TRITANIUM_TYPE_ID, $resource->typeId);
        $this->assertSame(self::TRITANIUM_NAME, $resource->typeName);
    }

    public function testDeletedFavoriteMissingFromSdeHasUnknownTypeName(): void
    {
        $resource = $this->providerForFavoriteOf(self::ROBOTICS_TYPE_ID)
            ->provide(new Delete(), ['typeId' => (string) self::ROBOTICS_TYPE_ID]);

        $this->assertSame(self::ROBOTICS_TYPE_ID, $resource->typeId);
        $this->assertNull($resource->typeName);
    }

    private function providerForFavoriteOf(int $typeId): MarketFavoriteDeleteProvider
    {
        $user = $this->createStub(User::class);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $favorite = (new MarketFavorite())->setUser($user)->setTypeId($typeId);
        $favoriteRepository = $this->createStub(MarketFavoriteRepository::class);
        $favoriteRepository->method('findByUserAndType')->willReturnCallback(
            static fn (User $owner, int $requestedTypeId): ?MarketFavorite => $requestedTypeId === $typeId ? $favorite : null,
        );

        return new MarketFavoriteDeleteProvider(
            $security,
            $favoriteRepository,
            new TypeNameResolver($this->sdeTypeRepositoryKnowing([self::TRITANIUM_TYPE_ID => self::TRITANIUM_NAME])),
        );
    }
}
