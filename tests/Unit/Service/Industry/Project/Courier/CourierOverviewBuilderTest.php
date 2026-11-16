<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

use App\Entity\CachedStructure;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use App\Entity\User;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ItemVolumes;
use App\Industry\Application\JumpDistances;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\Project\Courier\CourierContractReader;
use App\Service\Industry\Project\Courier\CourierOverview;
use App\Service\Industry\Project\Courier\CourierOverviewBuilder;
use App\Service\Industry\Project\Courier\CourierQuote;
use App\Service\Industry\Project\Courier\CourierQuoteCalculator;
use App\Service\Industry\Project\Courier\PurchaseOriginResolver;
use App\Service\Industry\Project\IndustryProjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourierOverviewBuilder::class)]
#[Group('issue-1022')]
final class CourierOverviewBuilderTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int NOCXIUM = 38;
    private const int MORPHITE = 11_399;
    private const int TYPE_WITHOUT_VOLUME = 999_022;
    private const float TRITANIUM_VOLUME = 0.01;
    private const float NOCXIUM_VOLUME = 0.05;
    private const float MORPHITE_VOLUME = 0.5;

    private const int JITA = 30_000_142;
    private const int PERIMETER = 30_000_144;
    private const int AMARR = 30_002_187;
    private const int RENS = 30_002_510;
    private const int JITA_STATION = 60_003_760;
    private const int PERIMETER_STATION = 60_004_588;
    private const int AMARR_STATION = 60_008_494;
    private const int RENS_STATION = 60_004_111;
    private const int AMARR_STRUCTURE = 1_035_466_617_946;
    private const int UNKNOWN_STRUCTURE = 1_035_466_999_999;
    private const int JUMPS_JITA_TO_PERIMETER = 1;
    private const int JUMPS_AMARR_TO_PERIMETER = 9;
    private const int JUMPS_RENS_TO_PERIMETER = 6;
    private const int OWNER_PILOT_ID = 2_112_022_001;

    private ?int $manufacturingSystemId = self::PERIMETER;
    private User $owner;
    private IndustryProject $project;
    private array $contractsOfTheOwner = [];
    private CourierOverviewBuilder $builder;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner);

        $linkRepository = $this->createStub(IndustryProjectCourierContractRepository::class);
        $linkRepository->method('findOneByProject')->willReturn(null);

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(fn (): array => $this->contractsOfTheOwner);
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');

        $itemVolumes = $this->createStub(ItemVolumes::class);
        $itemVolumes->method('volumeOf')->willReturnCallback(static fn (int $typeId): ?float => match ($typeId) {
            self::TRITANIUM => self::TRITANIUM_VOLUME,
            self::NOCXIUM => self::NOCXIUM_VOLUME,
            self::MORPHITE => self::MORPHITE_VOLUME,
            default => null,
        });

        $jumpDistances = $this->createStub(JumpDistances::class);
        $jumpDistances->method('jumpsBetween')->willReturnCallback(static fn (int $from, int $to): ?int => self::PERIMETER === $to
            ? match ($from) {
                self::JITA => self::JUMPS_JITA_TO_PERIMETER,
                self::AMARR => self::JUMPS_AMARR_TO_PERIMETER,
                self::RENS => self::JUMPS_RENS_TO_PERIMETER,
                default => null,
            }
            : null);

        $projectService = $this->createStub(IndustryProjectService::class);
        $projectService->method('favoriteSystemsOf')->willReturnCallback(fn (): FavoriteSystems => new FavoriteSystems($this->manufacturingSystemId, null));

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturnCallback(fn (int $solarSystemId): ?MapSolarSystem => match ($solarSystemId) {
            self::JITA => $this->solarSystem(self::JITA, 'Jita'),
            self::PERIMETER => $this->solarSystem(self::PERIMETER, 'Perimeter'),
            self::AMARR => $this->solarSystem(self::AMARR, 'Amarr'),
            self::RENS => $this->solarSystem(self::RENS, 'Rens'),
            default => null,
        });

        $stationRepository = $this->createStub(StaStationRepository::class);
        $stationRepository->method('findByStationId')->willReturnCallback(fn (int $stationId): ?StaStation => match ($stationId) {
            self::JITA_STATION => $this->stationIn(self::JITA_STATION, self::JITA),
            self::PERIMETER_STATION => $this->stationIn(self::PERIMETER_STATION, self::PERIMETER),
            self::AMARR_STATION => $this->stationIn(self::AMARR_STATION, self::AMARR),
            self::RENS_STATION => $this->stationIn(self::RENS_STATION, self::RENS),
            default => null,
        });
        $cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $cachedStructureRepository->method('findByStructureId')->willReturnCallback(
            static fn (int $structureId): ?CachedStructure => self::AMARR_STRUCTURE === $structureId
                ? (new CachedStructure())->setStructureId($structureId)->setSolarSystemId(self::AMARR)
                : null,
        );
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByLocationId')->willReturn(null);

        $this->builder = new CourierOverviewBuilder(
            $linkRepository,
            new CourierContractReader($esiClient, $tokenManager),
            new CourierQuoteCalculator(),
            $itemVolumes,
            $jumpDistances,
            $projectService,
            $solarSystemRepository,
            new PurchaseOriginResolver($stationRepository, $cachedStructureRepository, $structureConfigRepository),
        );
    }

    public function testPurchasesMadeInJitaAreQuotedFromJitaToTheWorkshop(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);

        $overview = $this->overview();

        self::assertSame([self::JITA, 'Jita', self::PERIMETER, 'Perimeter'], $this->routeOf($overview));
        self::assertSame([10.0, 1_000_000, 1_500, 1, 'ready', []], $this->quoteRow($overview->quote));
        self::assertSame([[], []], [$overview->otherOrigins, $overview->unknownOriginLines]);
    }

    public function testPurchasesMadeInAnotherHubAreQuotedFromThatHubWithItsOwnJumps(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::AMARR_STATION);

        $overview = $this->overview();

        self::assertSame([self::AMARR, 'Amarr', self::PERIMETER, 'Perimeter'], $this->routeOf($overview));
        self::assertSame([10.0, 1_000_000, 13_500, 9, 'ready', []], $this->quoteRow($overview->quote));
        self::assertSame([[], []], [$overview->otherOrigins, $overview->unknownOriginLines]);
    }

    public function testPurchasesMadeInTheWorkshopSystemNeedNoTransport(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::PERIMETER_STATION);

        $overview = $this->overview();

        self::assertSame([null, null, self::PERIMETER, 'Perimeter'], $this->routeOf($overview));
        self::assertSame([null, null, null, null, 'no_purchases', []], $this->quoteRow($overview->quote));
        self::assertSame([[], [], []], [$overview->otherOrigins, $overview->unknownOriginLines, $overview->lines]);
    }

    public function testTheOriginWithTheLargestCollateralIsTheMainOneAndTheOtherIsListed(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buy(self::NOCXIUM, 100, 5_000_000.0, self::AMARR_STATION);

        $overview = $this->overview();

        self::assertSame([self::AMARR, 'Amarr'], [$overview->originSolarSystemId, $overview->originSolarSystemName]);
        self::assertSame([5.0, 5_000_000, 6_750, 9, 'ready', []], $this->quoteRow($overview->quote));
        self::assertSame(
            [[self::JITA, 'Jita', 10.0, 1_000_000, 1_500, 1, 'ready', []]],
            array_map($this->originRow(...), $overview->otherOrigins),
        );
    }

    public function testOtherOriginsAreSortedByDescendingCollateral(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buy(self::NOCXIUM, 100, 5_000_000.0, self::AMARR_STATION);
        $this->buy(self::MORPHITE, 20, 3_000_000.0, self::RENS_STATION);

        $overview = $this->overview();

        self::assertSame(self::AMARR, $overview->originSolarSystemId);
        self::assertSame(
            [[self::RENS, 'Rens', 10.0, 3_000_000, 9_000, 6, 'ready', []], [self::JITA, 'Jita', 10.0, 1_000_000, 1_500, 1, 'ready', []]],
            array_map($this->originRow(...), $overview->otherOrigins),
        );
    }

    public function testOriginsWithTheSameCollateralKeepTheSmallestSolarSystemIdAsMain(): void
    {
        $this->buy(self::NOCXIUM, 100, 1_000_000.0, self::AMARR_STATION);
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);

        $overview = $this->overview();

        self::assertSame(self::JITA, $overview->originSolarSystemId);
        self::assertSame([self::AMARR], array_map(static fn ($origin): int => $origin->solarSystemId, $overview->otherOrigins));
    }

    public function testQuantityBoughtInTheWorkshopSystemIsLeftOutOfTheQuoteAndTheLine(): void
    {
        $line = $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buyOn($line, 500, 500_000.0, self::PERIMETER_STATION);

        $overview = $this->overview();

        self::assertSame([10.0, 1_000_000, 1_500, 1, 'ready', []], $this->quoteRow($overview->quote));
        self::assertSame([[$line->getId()->toRfc4122(), self::TRITANIUM, 1_000]], $this->lineRows($overview));
    }

    public function testTheSameTypeBoughtInTwoHubsIsQuotedOnceForEachHub(): void
    {
        $line = $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buyOn($line, 400, 500_000.0, self::AMARR_STATION);

        $overview = $this->overview();

        self::assertSame([self::JITA, [10.0, 1_000_000, 1_500, 1, 'ready', []]], [$overview->originSolarSystemId, $this->quoteRow($overview->quote)]);
        self::assertSame(
            [[self::AMARR, 'Amarr', 4.0, 500_000, 5_400, 9, 'ready', []]],
            array_map($this->originRow(...), $overview->otherOrigins),
        );
        self::assertSame([[$line->getId()->toRfc4122(), self::TRITANIUM, 1_400]], $this->lineRows($overview));
    }

    public function testManualPurchasesAreAnUnknownOriginAndNeverQuotedFromJita(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, null);

        $overview = $this->overview();

        self::assertSame([null, null], [$overview->originSolarSystemId, $overview->originSolarSystemName]);
        self::assertSame([null, null, null, null, 'no_purchases', []], $this->quoteRow($overview->quote));
        self::assertSame([[self::TRITANIUM, 1_000]], $this->unknownRows($overview));
        self::assertSame([[], []], [$overview->otherOrigins, $overview->lines]);
    }

    public function testManualQuantityOfAJitaTypeIsKeptOutOfTheQuoteAndListedAsUnknownOrigin(): void
    {
        $line = $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buyOn($line, 300, 300_000.0, null);

        $overview = $this->overview();

        self::assertSame([10.0, 1_000_000, 1_500, 1, 'ready', []], $this->quoteRow($overview->quote));
        self::assertSame([[self::TRITANIUM, 300]], $this->unknownRows($overview));
        self::assertSame([[$line->getId()->toRfc4122(), self::TRITANIUM, 1_000]], $this->lineRows($overview));
    }

    public function testUnknownOriginQuantitiesAreSummedByTypeAndSortedByTypeId(): void
    {
        $this->buy(self::NOCXIUM, 100, 700_000.0, null);
        $tritaniumLine = $this->buy(self::TRITANIUM, 300, 300_000.0, null);
        $this->buyOn($tritaniumLine, 200, 200_000.0, null);

        $overview = $this->overview();

        self::assertSame([[self::TRITANIUM, 500], [self::NOCXIUM, 100]], $this->unknownRows($overview));
    }

    public function testAPurchaseInAStructureUnknownToTheCacheAndTheConfigsIsAnUnknownOrigin(): void
    {
        $this->buy(self::MORPHITE, 20, 3_000_000.0, self::UNKNOWN_STRUCTURE);

        $overview = $this->overview();

        self::assertSame('no_purchases', $overview->quote->quoteStatus);
        self::assertSame([[self::MORPHITE, 20]], $this->unknownRows($overview));
    }

    public function testAPurchaseOfQuantityZeroIsIgnoredWhateverItsOrigin(): void
    {
        $line = $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buyOn($line, 0, 0.0, self::AMARR_STATION);
        $this->buyOn($line, 0, 0.0, null);

        $overview = $this->overview();

        self::assertSame([[], []], [$overview->otherOrigins, $overview->unknownOriginLines]);
        self::assertSame([[$line->getId()->toRfc4122(), self::TRITANIUM, 1_000]], $this->lineRows($overview));
    }

    public function testWithoutManufacturingSystemTheOriginIsKeptAndTheQuoteHasNoRoute(): void
    {
        $this->manufacturingSystemId = null;
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::AMARR_STATION);

        $overview = $this->overview();

        self::assertSame([self::AMARR, 'Amarr', null, null], $this->routeOf($overview));
        self::assertSame([10.0, 1_000_000, null, null, 'workshop_unknown', []], $this->quoteRow($overview->quote));
    }

    public function testAMissingVolumeOnlyAffectsTheOriginOfThatType(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buy(self::TYPE_WITHOUT_VOLUME, 3, 5_000_000.0, self::AMARR_STATION);

        $overview = $this->overview();

        self::assertSame(self::AMARR, $overview->originSolarSystemId);
        self::assertSame([null, 5_000_000, null, 9, 'volume_unknown', [self::TYPE_WITHOUT_VOLUME]], $this->quoteRow($overview->quote));
        self::assertSame(
            [[self::JITA, 'Jita', 10.0, 1_000_000, 1_500, 1, 'ready', []]],
            array_map($this->originRow(...), $overview->otherOrigins),
        );
    }

    public function testContractCandidatesAreMatchedAgainstTheMainOriginOnly(): void
    {
        $this->buy(self::TRITANIUM, 1_000, 1_000_000.0, self::JITA_STATION);
        $this->buy(self::NOCXIUM, 100, 5_000_000.0, self::AMARR_STATION);
        $this->owner->addCharacter($this->pilotWithToken());
        $this->contractsOfTheOwner = [
            $this->courierContract(1_022_001, '+1 minute', 5.0, 6_750.0),
            $this->courierContract(1_022_002, '+2 minutes', 10.0, 1_500.0),
        ];

        $overview = $this->builder->build($this->project, $this->owner);

        self::assertSame(
            [[1_022_002, false], [1_022_001, true]],
            array_map(static fn ($candidate): array => [$candidate->contract->contractId, $candidate->matchesQuote], $overview->candidates),
        );
    }

    private function overview(): CourierOverview
    {
        return $this->builder->build($this->project, new User());
    }

    private function buy(int $typeId, int $quantity, float $totalPrice, ?int $locationId): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName('Type '.$typeId)->setRequiredQuantity($quantity)->setIsJob(false);
        $this->project->addBomItem($line);

        return $this->buyOn($line, $quantity, $totalPrice, $locationId);
    }

    private function buyOn(IndustryProjectBomLine $line, int $quantity, float $totalPrice, ?int $locationId): IndustryProjectBomLine
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($line->getTypeId())
            ->setQuantity($quantity)
            ->setUnitPrice($quantity > 0 ? $totalPrice / $quantity : 0.0)
            ->setTotalPrice($totalPrice)
            ->setSource(null === $locationId ? 'manual' : 'esi_wallet');
        if (null !== $locationId) {
            $purchase->setTransaction((new CachedWalletTransaction())->setLocationId($locationId));
        }
        $line->addPurchase($purchase);

        return $line;
    }

    private function solarSystem(int $solarSystemId, string $name): MapSolarSystem
    {
        return (new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name);
    }

    private function stationIn(int $stationId, int $solarSystemId): StaStation
    {
        return (new StaStation())->setStationId($stationId)->setSolarSystem((new MapSolarSystem())->setSolarSystemId($solarSystemId));
    }

    /**
     * @return array{?int, ?string, ?int, ?string}
     */
    private function routeOf(CourierOverview $overview): array
    {
        return [$overview->originSolarSystemId, $overview->originSolarSystemName, $overview->workshopSolarSystemId, $overview->workshopSolarSystemName];
    }

    private function quoteRow(CourierQuote $quote): array
    {
        return [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus, $quote->missingVolumeTypeIds];
    }

    private function originRow(object $origin): array
    {
        return [$origin->solarSystemId, $origin->solarSystemName, ...$this->quoteRow($origin->quote)];
    }

    private function lineRows(CourierOverview $overview): array
    {
        return array_map(static fn ($line): array => [$line->bomLineId, $line->typeId, $line->purchasedQuantity], $overview->lines);
    }

    private function unknownRows(CourierOverview $overview): array
    {
        return array_map(static fn ($line): array => [$line->typeId, $line->quantity], $overview->unknownOriginLines);
    }

    private function pilotWithToken(): Character
    {
        return (new Character())->setEveCharacterId(self::OWNER_PILOT_ID)->setName('Pilot')->setEveToken((new EveToken())
            ->setAccessToken('access-owner')
            ->setRefreshTokenEncrypted('refresh-owner')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['esi-contracts.read_character_contracts.v1']));
    }

    private function courierContract(int $contractId, string $issuedAt, float $volume, float $reward): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'courier',
            'status' => 'outstanding',
            'date_issued' => (new \DateTimeImmutable($issuedAt))->format('Y-m-d\TH:i:sP'),
            'volume' => $volume,
            'reward' => $reward,
            'collateral' => 1_000_000.0,
            'start_location_id' => self::JITA_STATION,
            'end_location_id' => self::AMARR_STRUCTURE,
        ];
    }
}
