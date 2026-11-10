<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningValuationProfile;
use App\Entity\MoonComposition;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Repository\MiningValuationProfileRepository;
use App\Repository\MoonCompositionRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\MoonChunk\MoonChunkValuer;
use App\Service\MoonChunk\MoonChunkValueReader;
use App\Service\MoonChunk\MoonChunkVolumeEstimator;
use App\Tests\Unit\Service\Mining\BuildsMiningProfileUnitPrices;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonChunkValueReader::class)]
#[Group('issue-532')]
final class MoonChunkValueReaderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;
    use BuildsMiningProfileUnitPrices;

    private const int CORPORATION_ID = 98_700_001;
    private const int MOON_ID = 40_000_011;
    private const float VALUE_DELTA = 1e-3;
    private const float CHUNK_VOLUME_M3 = 1_000_000.0;
    private const float ORE_A_UNITS = 10_000_000.0;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-10T12:00:00+00:00');
    }

    public function testAChunkIsValuedAtTheUnitValueOfTheDefaultProfileAndCarriesItsSource(): void
    {
        $extraction = $this->extraction();

        $view = $this->readerWith(null)->read(new User(), [$extraction], $this->now)[$extraction];

        $this->assertSame('known', $view['status']);
        $this->assertEqualsWithDelta(self::ORE_A_UNITS * 500.0, $view['totalValue'], self::VALUE_DELTA);
        $this->assertSame(['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureName' => null], $view['valuationSource']);
    }

    public function testAChunkFollowsTheSideOfTheProfileOfTheUserAndCarriesItsSource(): void
    {
        $extraction = $this->extraction();
        $profile = $this->miningValuationProfileOf(side: MarketSide::Sell);

        $view = $this->readerWith($profile)->read(new User(), [$extraction], $this->now)[$extraction];

        $this->assertEqualsWithDelta(self::ORE_A_UNITS * 560.0, $view['totalValue'], self::VALUE_DELTA);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'form' => 'compressed', 'structureName' => null], $view['valuationSource']);
    }

    public function testTheExportTaxOfTheProfileLowersTheChunkValue(): void
    {
        $extraction = $this->extraction();

        $view = $this->readerWith($this->miningValuationProfileOf(exportTax: 1000.0))->read(new User(), [$extraction], $this->now)[$extraction];

        $this->assertEqualsWithDelta(self::ORE_A_UNITS * 400.0, $view['totalValue'], self::VALUE_DELTA);
    }

    public function testTheProfileReadIsTheOneOfTheUserPassedToTheReader(): void
    {
        $extraction = $this->extraction();
        $seller = new User();
        $buyer = new User();
        $profileRepository = $this->createStub(MiningValuationProfileRepository::class);
        $profileRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?MiningValuationProfile => ($criteria['user'] ?? null) === $seller ? $this->miningValuationProfileOf(side: MarketSide::Sell) : null,
        );
        $reader = $this->readerOn($this->createMiningProfileUnitPrices(
            $this->jitaBuyPricesOfOreAOfTheSpec(),
            $this->jitaSellPricesOfOreAOfTheSpec(),
            null,
            null,
            null,
            $this->sdeTypesWithOreAOfTheSpec(),
            $profileRepository,
        ));

        $sellerView = $reader->read($seller, [$extraction], $this->now)[$extraction];
        $buyerView = $reader->read($buyer, [$extraction], $this->now)[$extraction];

        $this->assertEqualsWithDelta(self::ORE_A_UNITS * 560.0, $sellerView['totalValue'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(self::ORE_A_UNITS * 500.0, $buyerView['totalValue'], self::VALUE_DELTA);
    }

    private function readerWith(?MiningValuationProfile $profile): MoonChunkValueReader
    {
        return $this->readerOn($this->createMiningProfileUnitPrices(
            $this->jitaBuyPricesOfOreAOfTheSpec(),
            $this->jitaSellPricesOfOreAOfTheSpec(),
            $profile,
            null,
            null,
            $this->sdeTypesWithOreAOfTheSpec(),
        ));
    }

    private function readerOn(MiningProfileUnitPrices $unitPrices): MoonChunkValueReader
    {
        $composition = (new MoonComposition())
            ->setCorporationId(self::CORPORATION_ID)
            ->setMoonId(self::MOON_ID)
            ->setRecordedBy(new User())
            ->setRecordedAt(new \DateTimeImmutable('2026-11-09T08:00:00+00:00'))
            ->setLines([['typeId' => self::VELDSPAR, 'share' => 1.0]]);
        $compositionRepository = $this->createStub(MoonCompositionRepository::class);
        $compositionRepository->method('findBy')->willReturn([$composition]);
        $veldspar = (new InvType())->setTypeId(self::VELDSPAR)->setTypeName('Veldspar')->setVolume(0.1)->setPortionSize(100)->setPublished(true);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturn([self::VELDSPAR => $veldspar]);

        return new MoonChunkValueReader($compositionRepository, $invTypeRepository, $unitPrices, new MoonChunkVolumeEstimator(), new MoonChunkValuer());
    }

    private function extraction(): CorporationMiningExtraction
    {
        return (new CorporationMiningExtraction())
            ->setCorporationId(self::CORPORATION_ID)
            ->setStructureId(1_035_000_000_001)
            ->setStructureName('Athanor Ilyra')
            ->setMoonId(self::MOON_ID)
            ->setMoonName('Ilyra IV - Moon 1')
            ->setExtractionStartTime(new \DateTimeImmutable('2026-11-09T20:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable('2026-11-11T12:00:00+00:00'))
            ->setNaturalDecayTime(new \DateTimeImmutable('2026-11-14T12:00:00+00:00'));
    }
}
