<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Ledger\MiningEntryResource;
use App\Entity\MiningEntry;
use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Repository\MiningEntryRepository;
use App\Repository\MiningValuationProfileRepository;
use App\Repository\ReprocessingProfileRepository;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\StructureMarketService;
use App\State\Provider\Ledger\MiningEntryCollectionProvider;
use App\Tests\Unit\Service\Mining\BuildsMiningProfileUnitPrices;
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
    use BuildsMiningProfileUnitPrices;

    private const int PYROXERES = 1224;
    private const int ORE_MISSING_FROM_SDE = 62568;
    private const int MARKET_STRUCTURE_ID = 1_022_734_985_679;
    private const float PRICE_DELTA = 1e-9;
    private const array DEFAULT_SOURCE = ['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureName' => null];

    public function testEntryOfAnOreMissingFromTheSdeIsListedWithAnUnknownNameAndItsQuantityAndValue(): void
    {
        $user = new User();
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::ORE_MISSING_FROM_SDE, null, 15_587, 41.5),
            $this->entry($user, '2026-10-07', self::PYROXERES, 'Pyroxeres', 431, 512.0),
            $this->entry($user, '2026-10-06', self::VELDSPAR, 'Veldspar', 100, 17.2),
        ]);

        $entries = $this->providerFor($user, $miningEntryRepository, $this->createMiningProfileUnitPrices())->provide(new GetCollection());

        $this->assertSame([
            [self::ORE_MISSING_FROM_SDE, null, 15_587, 41.5, 646_860.5, null],
            [self::PYROXERES, 'Pyroxeres', 431, 512.0, 220_672.0, null],
            [self::VELDSPAR, 'Veldspar', 100, 17.2, 1_720.0, 17.5],
        ], array_map(static fn (MiningEntryResource $entry): array => [
            $entry->typeId,
            $entry->typeName,
            $entry->quantity,
            $entry->unitPrice,
            $entry->totalValue,
            $entry->unitValue,
        ], $entries));
    }

    public function testEntryCarriesTheUnitValueAndTheValuationSourceOfTheDefaultProfile(): void
    {
        $entry = $this->provideVeldsparEntry();

        $this->assertEqualsWithDelta(17.5, $entry->unitValue, self::PRICE_DELTA);
        $this->assertSame(self::DEFAULT_SOURCE, $entry->valuationSource);
        $this->assertEqualsWithDelta(17.5, $entry->compressedEquivalentPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(15.2, $entry->rawUnitPrice, self::PRICE_DELTA);
        $this->assertSame(17.2, $entry->unitPrice);
        $this->assertSame(206_400.0, $entry->totalValue);
    }

    #[Group('issue-259')]
    #[Group('issue-260')]
    public function testEntryExposesTheStructureColumnsWhenTheProfileRemembersAStructureEvenOnTheJitaMarket(): void
    {
        $profile = $this->miningValuationProfileOf(structureId: self::MARKET_STRUCTURE_ID, structureName: 'Keepstar Z-9');
        $structureMarket = $this->structureMarketReadAt(self::MARKET_STRUCTURE_ID, [], [self::COMPRESSED_VELDSPAR => 1100.0]);

        $entry = $this->provideVeldsparEntry($profile, null, [], $structureMarket);

        $this->assertEqualsWithDelta(1100.0, $entry->structureCompressedUnitPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(1100.0, $entry->structureCompressedEquivalentPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(15.2, $entry->rawUnitPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.5, $entry->unitValue, self::PRICE_DELTA);
        $this->assertSame(['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureName' => 'Keepstar Z-9'], $entry->valuationSource);
    }

    public function testTheStructureIdAndTheExportTaxOfTheQueryAreIgnoredInFavourOfTheProfile(): void
    {
        $structureMarket = $this->structureMarketReadAt(self::MARKET_STRUCTURE_ID, [], [self::COMPRESSED_VELDSPAR => 1100.0]);

        $entry = $this->provideVeldsparEntry(null, null, ['structureId' => (string) self::MARKET_STRUCTURE_ID, 'exportTax' => '1200'], $structureMarket);

        $this->assertNull($entry->structureCompressedEquivalentPrice);
        $this->assertNull($entry->structureUnitPrice);
        $this->assertEqualsWithDelta(15.2, $entry->rawUnitPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.5, $entry->unitValue, self::PRICE_DELTA);
    }

    public function testTheExportTaxOfTheProfileLowersTheJitaPricesPerCubicMetre(): void
    {
        $entry = $this->provideVeldsparEntry($this->miningValuationProfileOf(exportTax: 100.0));

        $this->assertEqualsWithDelta(17.4, $entry->unitValue, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.4, $entry->compressedEquivalentPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(5.2, $entry->rawUnitPrice, self::PRICE_DELTA);
    }

    public function testTheSellRawProfileValuesTheEntryAtTheRawJitaSellPrice(): void
    {
        $entry = $this->provideVeldsparEntry($this->miningValuationProfileOf(side: MarketSide::Sell, form: MiningValuationForm::Raw));

        $this->assertEqualsWithDelta(18.37, $entry->unitValue, self::PRICE_DELTA);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'form' => 'raw', 'structureName' => null], $entry->valuationSource);
    }

    public function testEntriesAreValuedWithTheProfileOfTheAuthenticatedUserOnly(): void
    {
        $user = new User();
        $profileRepository = $this->createStub(MiningValuationProfileRepository::class);
        $profileRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?MiningValuationProfile => ($criteria['user'] ?? null) === $user ? $this->miningValuationProfileOf(side: MarketSide::Sell) : null,
        );
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::VELDSPAR, 'Veldspar', 12_000, 17.2),
        ]);
        $unitPrices = $this->createMiningProfileUnitPrices(null, null, null, null, null, null, $profileRepository);

        $entry = $this->providerFor($user, $miningEntryRepository, $unitPrices)->provide(new GetCollection())[0];

        $this->assertEqualsWithDelta(19.61, $entry->unitValue, self::PRICE_DELTA);
    }

    public function testTheReprocessedProfileValuesTheEntryAtTheReprocessValueOfTheYield(): void
    {
        $entry = $this->provideVeldsparEntry(
            $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed),
            $this->profileRepositoryWithYield(0.7236),
        );

        $this->assertEqualsWithDelta(11.28816, $entry->unitValue, self::PRICE_DELTA);
        $this->assertSame(['market' => 'jita', 'side' => 'buy', 'form' => 'reprocessed', 'structureName' => null], $entry->valuationSource);
    }

    public function testTheReprocessedProfileLeavesTheUnitValueUnknownWithoutAYield(): void
    {
        $entry = $this->provideVeldsparEntry(
            $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed),
            $this->profileRepositoryWithNoProfile(),
        );

        $this->assertNull($entry->unitValue);
        $this->assertSame(['market' => 'jita', 'side' => 'buy', 'form' => 'reprocessed', 'structureName' => null], $entry->valuationSource);
        $this->assertEqualsWithDelta(17.5, $entry->compressedEquivalentPrice, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testEntryReprocessValueUsesTheYieldOfTheUserProfile(): void
    {
        $entry = $this->provideVeldsparEntry(null, $this->profileRepositoryWithYield(0.7236));

        $this->assertEqualsWithDelta(0.7236, $entry->reprocessYield, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(11.28816, $entry->reprocessValue, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testEntryReprocessValueIsUnknownWhenTheUserHasNoProfile(): void
    {
        $entry = $this->provideVeldsparEntry(null, $this->profileRepositoryWithNoProfile());

        $this->assertNull($entry->reprocessYield);
        $this->assertNull($entry->reprocessValue);
        $this->assertNull($entry->structureReprocessValue);
        $this->assertEqualsWithDelta(15.2, $entry->rawUnitPrice, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.5, $entry->compressedEquivalentPrice, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testEntryReprocessValueIsUnknownWhenTheProfileYieldIsUnreadable(): void
    {
        $entry = $this->provideVeldsparEntry(null, $this->profileRepositoryWithUnreadableYield());

        $this->assertNull($entry->reprocessYield);
        $this->assertNull($entry->reprocessValue);
        $this->assertEqualsWithDelta(17.5, $entry->compressedEquivalentPrice, self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testTheReprocessYieldQueryParameterNoLongerOverridesTheProfile(): void
    {
        $entry = $this->provideVeldsparEntry(null, $this->profileRepositoryWithYield(0.7236), ['reprocessYield' => '50']);

        $this->assertEqualsWithDelta(0.7236, $entry->reprocessYield, self::PRICE_DELTA);
        $this->assertEqualsWithDelta(11.28816, $entry->reprocessValue, self::PRICE_DELTA);
    }

    private function provideVeldsparEntry(
        ?MiningValuationProfile $profile = null,
        ?ReprocessingProfileRepository $reprocessingProfileRepository = null,
        array $query = [],
        ?StructureMarketService $structureMarketService = null,
    ): MiningEntryResource {
        $user = new User();
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('findByUserAndDateRange')->willReturn([
            $this->entry($user, '2026-10-08', self::VELDSPAR, 'Veldspar', 12_000, 17.2),
        ]);
        $unitPrices = $this->createMiningProfileUnitPrices(null, null, $profile, $reprocessingProfileRepository ?? $this->profileRepositoryWithYield(0.78), $structureMarketService);

        return $this->providerFor($user, $miningEntryRepository, $unitPrices, $query)->provide(new GetCollection())[0];
    }

    private function providerFor(
        User $user,
        MiningEntryRepository $miningEntryRepository,
        MiningProfileUnitPrices $unitPrices,
        array $query = [],
    ): MiningEntryCollectionProvider {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        return new MiningEntryCollectionProvider($security, $miningEntryRepository, $requestStack, $unitPrices);
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
