<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

use App\Entity\CachedStructure;
use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectPurchase;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\Project\Courier\PurchaseOriginResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurchaseOriginResolver::class)]
#[Group('issue-1022')]
final class PurchaseOriginResolverTest extends TestCase
{
    private const int AMARR_SOLAR_SYSTEM_ID = 30_002_187;
    private const int RENS_SOLAR_SYSTEM_ID = 30_002_510;
    private const int HEK_SOLAR_SYSTEM_ID = 30_002_053;
    private const int AMARR_STATION = 60_008_494;
    private const int MISSING_STATION = 60_999_999;
    private const int CACHED_STRUCTURE = 1_035_466_617_946;
    private const int CONFIGURED_STRUCTURE = 1_035_466_700_001;
    private const int CACHED_AND_CONFIGURED_STRUCTURE = 1_035_466_700_002;
    private const int UNRESOLVED_CACHED_STRUCTURE = 1_035_466_700_003;
    private const int UNKNOWN_STRUCTURE = 1_035_466_999_999;
    private const int FIRST_STRUCTURE_ID = 1_000_000_000;

    private PurchaseOriginResolver $resolver;

    protected function setUp(): void
    {
        $stationRepository = $this->createStub(StaStationRepository::class);
        $stationRepository->method('findByStationId')->willReturnCallback(
            fn (int $stationId): ?StaStation => self::AMARR_STATION === $stationId
                ? (new StaStation())->setStationId($stationId)->setSolarSystem((new MapSolarSystem())->setSolarSystemId(self::AMARR_SOLAR_SYSTEM_ID))
                : null,
        );
        $cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $cachedStructureRepository->method('findByStructureId')->willReturnCallback(
            static fn (int $structureId): ?CachedStructure => match ($structureId) {
                self::CACHED_STRUCTURE => (new CachedStructure())->setStructureId($structureId)->setSolarSystemId(self::AMARR_SOLAR_SYSTEM_ID),
                self::CACHED_AND_CONFIGURED_STRUCTURE => (new CachedStructure())->setStructureId($structureId)->setSolarSystemId(self::HEK_SOLAR_SYSTEM_ID),
                self::FIRST_STRUCTURE_ID => (new CachedStructure())->setStructureId($structureId)->setSolarSystemId(self::HEK_SOLAR_SYSTEM_ID),
                self::UNRESOLVED_CACHED_STRUCTURE => (new CachedStructure())->setStructureId($structureId)->setSolarSystemId(null),
                default => null,
            },
        );
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByLocationId')->willReturnCallback(
            static fn (int $locationId): ?IndustryStructureConfig => match ($locationId) {
                self::CONFIGURED_STRUCTURE => (new IndustryStructureConfig())->setLocationId($locationId)->setSolarSystemId(self::RENS_SOLAR_SYSTEM_ID),
                self::CACHED_AND_CONFIGURED_STRUCTURE => (new IndustryStructureConfig())->setLocationId($locationId)->setSolarSystemId(self::RENS_SOLAR_SYSTEM_ID),
                default => null,
            },
        );
        $this->resolver = new PurchaseOriginResolver($stationRepository, $cachedStructureRepository, $structureConfigRepository);
    }

    public function testAStationPurchaseResolvesToTheSolarSystemOfTheStation(): void
    {
        self::assertSame(self::AMARR_SOLAR_SYSTEM_ID, $this->resolver->solarSystemIdOf($this->purchaseAt(self::AMARR_STATION)));
    }

    public function testAStationMissingFromTheSdeIsAnUnknownOrigin(): void
    {
        self::assertNull($this->resolver->solarSystemIdOf($this->purchaseAt(self::MISSING_STATION)));
    }

    public function testAStructurePurchaseResolvesToTheSolarSystemOfTheCachedStructure(): void
    {
        self::assertSame(self::AMARR_SOLAR_SYSTEM_ID, $this->resolver->solarSystemIdOf($this->purchaseAt(self::CACHED_STRUCTURE)));
    }

    public function testAStructureMissingFromTheCacheResolvesToTheSolarSystemOfItsConfig(): void
    {
        self::assertSame(self::RENS_SOLAR_SYSTEM_ID, $this->resolver->solarSystemIdOf($this->purchaseAt(self::CONFIGURED_STRUCTURE)));
    }

    public function testTheCachedStructureWinsOverTheConfigOfTheSameStructure(): void
    {
        self::assertSame(self::HEK_SOLAR_SYSTEM_ID, $this->resolver->solarSystemIdOf($this->purchaseAt(self::CACHED_AND_CONFIGURED_STRUCTURE)));
    }

    public function testAStructureUnknownToTheCacheAndTheConfigsIsAnUnknownOrigin(): void
    {
        self::assertNull($this->resolver->solarSystemIdOf($this->purchaseAt(self::UNKNOWN_STRUCTURE)));
    }

    public function testACachedStructureWithoutSolarSystemIsAnUnknownOrigin(): void
    {
        self::assertNull($this->resolver->solarSystemIdOf($this->purchaseAt(self::UNRESOLVED_CACHED_STRUCTURE)));
    }

    public function testTheFirstStructureIdIsResolvedAsAStructureNotAStation(): void
    {
        self::assertSame(self::HEK_SOLAR_SYSTEM_ID, $this->resolver->solarSystemIdOf($this->purchaseAt(self::FIRST_STRUCTURE_ID)));
    }

    public function testAManualPurchaseWithoutTransactionIsAnUnknownOrigin(): void
    {
        $purchase = (new IndustryProjectPurchase())->setTypeId(34)->setQuantity(1_000)->setSource('manual');

        self::assertNull($this->resolver->solarSystemIdOf($purchase));
    }

    private function purchaseAt(int $locationId): IndustryProjectPurchase
    {
        return (new IndustryProjectPurchase())
            ->setTypeId(34)
            ->setQuantity(1_000)
            ->setSource('esi_wallet')
            ->setTransaction((new CachedWalletTransaction())->setLocationId($locationId));
    }
}
