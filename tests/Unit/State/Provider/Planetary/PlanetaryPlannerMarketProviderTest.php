<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryPlannerMarketResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Exception\PlanetSchematicIncompleteException;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryMarketStatsReader;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryProductTypeResolver;
use App\State\Provider\Planetary\PlanetaryPlannerMarketProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryPlannerMarketProvider::class)]
#[Group('issue-1404')]
final class PlanetaryPlannerMarketProviderTest extends TestCase
{
    private const int WATER = 91_011;
    private const int REACTIVE_METALS = 91_012;
    private const int COOLANT = 91_021;
    private const int ROBOTICS = 91_031;
    private const int BROADCAST_NODE = 91_041;
    private const int THE_FORGE = EveConstants::THE_FORGE_REGION_ID;

    public function testTheResourceCarriesTheForgeAndTheStatsOfTheP2P3AndP4ProductsUnderTheFixedIdentifier(): void
    {
        $resource = $this->provide(new User(), [
            self::COOLANT => [['date' => '2026-03-01', 'average' => 1_010.0, 'volume' => 12_000]],
            self::ROBOTICS => [['date' => '2026-03-02', 'average' => 29_800.0, 'volume' => 460]],
        ]);

        $this->assertSame('market', $resource->id);
        $this->assertSame(self::THE_FORGE, $resource->regionId);
        $this->assertSame([
            ['typeId' => self::COOLANT, 'averageDailyVolume' => 12_000.0, 'averagePrice' => 1_010.0, 'priceChange' => null, 'lastDate' => '2026-03-01'],
            ['typeId' => self::ROBOTICS, 'averageDailyVolume' => 460.0, 'averagePrice' => 29_800.0, 'priceChange' => null, 'lastDate' => '2026-03-02'],
        ], $resource->products);
    }

    public function testAProductWithoutHistoryIsAbsentAndTheP1IsNeverAsked(): void
    {
        $askedTypeIds = [];
        $resource = $this->provide(new User(), [self::BROADCAST_NODE => [['date' => '2026-03-03', 'average' => 880_000.0, 'volume' => 40]]], $askedTypeIds);

        $this->assertSame([self::BROADCAST_NODE], array_column($resource->products, 'typeId'));
        $this->assertSame([self::COOLANT, self::ROBOTICS, self::BROADCAST_NODE], $askedTypeIds);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null, []);
    }

    public function testAnIncompleteSchematicOfTheSdeIsNotHidden(): void
    {
        $this->expectException(PlanetSchematicIncompleteException::class);

        $this->provide(new User(), [], schematicMap: [305 => ['inputs' => [self::WATER => 40], 'output' => null]]);
    }

    private function provide(?User $user, array $entriesByType, array &$askedTypeIds = [], ?array $schematicMap = null): PlanetaryPlannerMarketResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap ?? [
            301 => ['inputs' => [self::WATER => 40, self::REACTIVE_METALS => 40], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]],
            302 => ['inputs' => [self::COOLANT => 10], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 3]],
            303 => ['inputs' => [self::ROBOTICS => 6], 'output' => ['typeId' => self::BROADCAST_NODE, 'quantity' => 1]],
            304 => ['inputs' => [self::REACTIVE_METALS => 3000], 'output' => ['typeId' => self::WATER, 'quantity' => 20]],
        ]);
        $tierByTypeId = [self::WATER => 'P1', self::COOLANT => 'P2', self::ROBOTICS => 'P3', self::BROADCAST_NODE => 'P4'];
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('findProductTier')->willReturnCallback(static fn (int $typeId): ?string => $tierByTypeId[$typeId] ?? null);

        $historyRepository = $this->createStub(MarketPriceHistoryRepository::class);
        $historyRepository->method('findLatestEntriesByType')->willReturnCallback(
            static function (array $typeIds, int $regionId) use ($entriesByType, &$askedTypeIds): array {
                $askedTypeIds = $typeIds;

                return $regionId === self::THE_FORGE ? array_intersect_key($entriesByType, array_flip($typeIds)) : [];
            },
        );

        return (new PlanetaryPlannerMarketProvider(
            $security,
            new PlanetaryProductTypeResolver($schematicTypeRepository, $productionCalculator),
            new PlanetaryMarketStatsReader($historyRepository),
        ))->provide(new Get());
    }
}
