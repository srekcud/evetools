<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Ledger\MiningEntryResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\ReprocessingProfileRepository;
use App\Service\OreValueService;
use App\State\Provider\Ledger\MiningEntryCollectionProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MiningEntryCollectionProvider::class)]
final class MiningEntryCollectionProviderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const int PYROXERES = 1224;
    private const int ORE_MISSING_FROM_SDE = 62568;
    private const int MARKET_STRUCTURE_ID = 1_022_734_985_679;
    private const float PRICE_DELTA = 1e-9;

    public function testEntryOfAnOreMissingFromTheSdeIsListedWithAnUnknownNameAndItsQuantityAndValue(): void
    {
        $user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::ORE_MISSING_FROM_SDE, null, 15_587, 41.5),
            $this->entry($user, '2026-10-07', self::PYROXERES, 'Pyroxeres', 431, 512.0),
        ]);
        $oreValueService = $this->createStub(OreValueService::class);
        $oreValueService->method('getBatchOrePrices')->willReturn([]);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $provider = new MiningEntryCollectionProvider($security, $miningEntryRepository, $requestStack, $oreValueService, $this->profileRepositoryWithYield(0.78));

        $entries = $provider->provide(new GetCollection());

        $this->assertSame([
            [self::ORE_MISSING_FROM_SDE, null, 15_587, 41.5, 646_860.5],
            [self::PYROXERES, 'Pyroxeres', 431, 512.0, 220_672.0],
        ], array_map(static fn (MiningEntryResource $entry): array => [
            $entry->typeId,
            $entry->typeName,
            $entry->quantity,
            $entry->unitPrice,
            $entry->totalValue,
        ], $entries));
    }

    #[Group('issue-259')]
    #[Group('issue-260')]
    public function testEntryExposesTheRawUnitPriceAndTheStructureCompressedEquivalentPrice(): void
    {
        $user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::VELDSPAR, 'Veldspar', 12_000, 17.2),
        ]);
        $oreValueService = $this->createOreValueService(
            $this->jitaSellPrices(),
            $this->structureMarketSelling(self::MARKET_STRUCTURE_ID, [self::COMPRESSED_VELDSPAR => 1100.0]),
        );
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['structureId' => (string) self::MARKET_STRUCTURE_ID]));
        $provider = new MiningEntryCollectionProvider($security, $miningEntryRepository, $requestStack, $oreValueService, $this->profileRepositoryWithYield(0.78));

        $entry = $provider->provide(new GetCollection())[0];

        $this->assertEqualsWithDelta(1100.0, $entry->structureCompressedUnitPrice, self::PRICE_DELTA);
        $this->assertObjectHasProperty('structureCompressedEquivalentPrice', $entry);
        $this->assertEqualsWithDelta(1100.0, $entry->structureCompressedEquivalentPrice, self::PRICE_DELTA);
        $this->assertObjectHasProperty('rawUnitPrice', $entry);
        $this->assertEqualsWithDelta(18.37, $entry->rawUnitPrice, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testEntryReprocessValueUsesTheYieldOfTheUserProfile(): void
    {
        $entry = $this->provideVeldsparEntry($this->profileRepositoryWithYield(0.7236));

        $this->assertEqualsWithDelta(0.7236, $entry->reprocessYield, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(13.3224 * 0.7236 / 0.78, $entry->reprocessValue, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testEntryReprocessValueIsUnknownWhenTheUserHasNoProfile(): void
    {
        $entry = $this->provideVeldsparEntry($this->profileRepositoryWithNoProfile());

        $this->assertNull($entry->reprocessYield);
        $this->assertNull($entry->reprocessValue);
        $this->assertNull($entry->structureReprocessValue);
        $this->assertEqualsWithDelta(18.37, $entry->rawUnitPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(19.61, $entry->compressedEquivalentPrice, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testEntryReprocessValueIsUnknownWhenTheProfileYieldIsUnreadable(): void
    {
        $entry = $this->provideVeldsparEntry($this->profileRepositoryWithUnreadableYield());

        $this->assertNull($entry->reprocessYield);
        $this->assertNull($entry->reprocessValue);
    }

    #[Group('issue-269')]
    public function testTheReprocessYieldQueryParameterNoLongerOverridesTheProfile(): void
    {
        $entry = $this->provideVeldsparEntry($this->profileRepositoryWithYield(0.7236), ['reprocessYield' => '50']);

        $this->assertEqualsWithDelta(0.7236, $entry->reprocessYield, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(13.3224 * 0.7236 / 0.78, $entry->reprocessValue, self::PRICE_DELTA);
    }

    private function provideVeldsparEntry(ReprocessingProfileRepository $profileRepository, array $query = []): MiningEntryResource
    {
        $user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::VELDSPAR, 'Veldspar', 12_000, 17.2),
        ]);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));
        $provider = new MiningEntryCollectionProvider(
            $security,
            $miningEntryRepository,
            $requestStack,
            $this->createOreValueService($this->jitaSellPrices()),
            $profileRepository,
        );

        return $provider->provide(new GetCollection())[0];
    }

    private function entry(User $user, string $date, int $typeId, ?string $typeName, int $quantity, float $unitPrice): MiningEntry
    {
        return (new MiningEntry())
            ->setUser($user)
            ->setCharacterId(91000002)
            ->setCharacterName('Dorin Halk')
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setSolarSystemId(30002813)
            ->setSolarSystemName('Tama')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice);
    }
}
