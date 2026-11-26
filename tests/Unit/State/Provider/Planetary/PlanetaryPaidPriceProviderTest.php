<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryPaidPriceResource;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\TypeNameResolver;
use App\State\Provider\Planetary\PlanetaryPaidPriceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryPaidPriceProvider::class)]
#[Group('issue-1396')]
final class PlanetaryPaidPriceProviderTest extends TestCase
{
    private const int WATER = 3645;

    public function testTheResourceCarriesThePeriodAndOneRowPerPiInputType(): void
    {
        $resource = $this->provide(new User());

        $this->assertSame(['paid-prices', 90], [$resource->id, $resource->days]);
        $this->assertSame([self::WATER], array_column($resource->prices, 'typeId'));
        $this->assertSame([null], array_column($resource->prices, 'paidPrice'));
        $this->assertSame([], $resource->unsyncedWallets);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $resource->since);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null);
    }

    private function provide(?User $user): PlanetaryPaidPriceResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            1 => ['inputs' => [self::WATER => 40], 'output' => ['typeId' => 9832, 'quantity' => 5]],
        ]);
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByCharactersSince')->willReturn([]);

        $calculator = new PlanetaryPaidPriceCalculator(
            $transactionRepository,
            $schematicTypeRepository,
            $this->createStub(TypeNameResolver::class),
            new WalletReadingStateReader($this->createStub(CharacterWalletReadingRepository::class)),
        );

        return (new PlanetaryPaidPriceProvider($security, $calculator))->provide(new Get());
    }
}
