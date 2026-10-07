<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Contract;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Contract\ContractResource;
use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Provider\Contract\ContractCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ContractCollectionProvider::class)]
class ContractCollectionProviderTest extends TestCase
{
    private const CHARACTER_ID = 90000001;
    private const MY_CONTRACT_ID = 1001;
    private const TRITANIUM_TYPE_ID = 34;
    private const PYERITE_TYPE_ID = 35;
    private const TYPE_ID_ABSENT_FROM_SDE = 999_999_999;
    private const UNKNOWN_TYPE_NAME = 'Unknown';
    private const DEFAULT_MARKET_STRUCTURE_ID = 1035466617946;
    private const PUBLIC_CONTRACTS_ENDPOINT = '/contracts/public/' . EveConstants::THE_FORGE_REGION_ID . '/';
    private const CHARACTER_CONTRACTS_ENDPOINT = '/characters/' . self::CHARACTER_ID . '/contracts/';

    private Security&Stub $security;
    private EsiClient&Stub $esiClient;
    private JitaMarketService&Stub $jitaMarketService;
    private StructureMarketService&Stub $structureMarketService;
    private InvTypeRepository&Stub $invTypeRepository;
    private RequestStack $requestStack;
    private ContractCollectionProvider $provider;

    /** @var array<int, string> SDE type names keyed by type ID */
    private array $sdeTypeNames = [
        self::TRITANIUM_TYPE_ID => 'Tritanium',
        self::PYERITE_TYPE_ID => 'Pyerite',
    ];

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->structureMarketService = $this->createStub(StructureMarketService::class);

        $this->security->method('getUser')->willReturn($this->createUserWithMainCharacter());
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([self::TRITANIUM_TYPE_ID => 1000.0]);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        // Type names come from the SDE: any ESI POST (e.g. /universe/names/) is unexpected.
        $this->esiClient->method('post')->willThrowException(new \LogicException('Unexpected ESI POST call'));

        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->invTypeRepository->method('findByTypeIds')->willReturnCallback($this->findSdeTypesByTypeIds(...));

        $this->requestStack = new RequestStack();
        $this->requestStack->push(new Request());

        $this->provider = $this->createProvider();
    }

    // ===========================================
    // Item type names (SDE)
    // ===========================================

    public function testItemTypeNameIsResolvedFromSde(): void
    {
        $this->stubEsi(publicContractsFirstPage: [], publicContractsAllPages: []);

        $contract = $this->provideSingleContract();

        $this->assertCount(1, $contract->items);
        $this->assertSame(self::TRITANIUM_TYPE_ID, $contract->items[0]->typeId);
        $this->assertSame('Tritanium', $contract->items[0]->typeName);
        $this->assertSame(1000, $contract->items[0]->quantity);
    }

    public function testItemTypeNamesAreResolvedWithoutCallingEsiUniverseNames(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects($this->never())->method('post');
        $this->esiClient = $esiClient;
        $this->provider = $this->createProvider();
        $this->stubEsi(publicContractsFirstPage: [], publicContractsAllPages: []);

        $contract = $this->provideSingleContract();

        $this->assertSame('Tritanium', $contract->items[0]->typeName);
    }

    public function testEachIncludedItemGetsItsOwnSdeTypeName(): void
    {
        $this->stubEsi(
            publicContractsFirstPage: [],
            publicContractsAllPages: [],
            contractItems: [
                ['type_id' => self::TRITANIUM_TYPE_ID, 'quantity' => 1000, 'is_included' => true],
                ['type_id' => self::PYERITE_TYPE_ID, 'quantity' => 500, 'is_included' => true],
            ],
        );

        $contract = $this->provideSingleContract();

        $this->assertSame(2, $contract->itemCount);
        $this->assertSame(self::TRITANIUM_TYPE_ID, $contract->items[0]->typeId);
        $this->assertSame('Tritanium', $contract->items[0]->typeName);
        $this->assertSame(1000, $contract->items[0]->quantity);
        $this->assertSame(self::PYERITE_TYPE_ID, $contract->items[1]->typeId);
        $this->assertSame('Pyerite', $contract->items[1]->typeName);
        $this->assertSame(500, $contract->items[1]->quantity);
    }

    public function testItemTypeAbsentFromSdeKeepsUnknownFallbackName(): void
    {
        $this->stubEsi(
            publicContractsFirstPage: [],
            publicContractsAllPages: [],
            contractItems: [
                ['type_id' => self::TRITANIUM_TYPE_ID, 'quantity' => 1000, 'is_included' => true],
                ['type_id' => self::TYPE_ID_ABSENT_FROM_SDE, 'quantity' => 3, 'is_included' => true],
            ],
        );

        $contract = $this->provideSingleContract();

        $this->assertSame(2, $contract->itemCount);
        $this->assertSame('Tritanium', $contract->items[0]->typeName);
        $this->assertSame(self::TYPE_ID_ABSENT_FROM_SDE, $contract->items[1]->typeId);
        // Fallback label already used by processContract() when a name cannot be resolved.
        $this->assertSame(self::UNKNOWN_TYPE_NAME, $contract->items[1]->typeName);
        $this->assertSame(3, $contract->items[1]->quantity);
    }

    public function testItemTypeNamesStillResolvedWhenPublicContractsEsiCallFails(): void
    {
        $this->stubEsi(
            publicContractsFirstPage: [],
            publicContractsAllPages: [],
            publicContractsError: new \RuntimeException('ESI 503 on public contracts'),
        );

        $contract = $this->provideSingleContract();

        $this->assertSame('Tritanium', $contract->items[0]->typeName);
        $this->assertSame(1_000_000.0, $contract->jitaValue);
    }

    // ===========================================
    // Public contracts comparison (pagination)
    // ===========================================

    public function testSimilarPublicContractOnSecondPageIsUsedInComparison(): void
    {
        $publicContractsPage1 = [$this->publicContract(5001, 2_000_000.0, 100.0)];
        $publicContractsPage2 = [$this->publicContract(6001, 1_000_000.0, 100.0)];
        $this->stubEsi(
            publicContractsFirstPage: $publicContractsPage1,
            publicContractsAllPages: [...$publicContractsPage1, ...$publicContractsPage2],
        );

        $contract = $this->provideSingleContract();

        $this->assertSame(2, $contract->similarCount);
        $this->assertSame(1_000_000.0, $contract->lowestSimilar);
        $this->assertSame(1_500_000.0, $contract->avgSimilar);
        $this->assertSame(100_000.0, $contract->similarDiff);
        $this->assertSame(10.0, $contract->similarDiffPercent);
        $this->assertFalse($contract->isCompetitive);
    }

    public function testSimilarPublicContractOnFirstPageIsUsedInComparison(): void
    {
        $publicContractsPage1 = [$this->publicContract(5001, 1_050_000.0, 100.0)];
        $this->stubEsi(
            publicContractsFirstPage: $publicContractsPage1,
            publicContractsAllPages: $publicContractsPage1,
        );

        $contract = $this->provideSingleContract();

        $this->assertSame(1, $contract->similarCount);
        $this->assertSame(1_050_000.0, $contract->lowestSimilar);
        $this->assertSame(1_050_000.0, $contract->avgSimilar);
        $this->assertSame(50_000.0, $contract->similarDiff);
        $this->assertEqualsWithDelta(4.7619047619, $contract->similarDiffPercent, 1e-9);
        $this->assertTrue($contract->isCompetitive);
    }

    public function testPublicContractsNotOutstandingItemExchangeOrOutsideVolumeToleranceAreIgnored(): void
    {
        $publicContracts = [
            $this->publicContract(5001, 500_000.0, 100.0, type: 'auction'),
            $this->publicContract(5002, 500_000.0, 100.0, status: 'finished'),
            $this->publicContract(5003, 500_000.0, 103.0),
            $this->publicContract(5004, 1_200_000.0, 101.0),
        ];
        $this->stubEsi(publicContractsFirstPage: $publicContracts, publicContractsAllPages: $publicContracts);

        $contract = $this->provideSingleContract();

        $this->assertSame(1, $contract->similarCount);
        $this->assertSame(1_200_000.0, $contract->lowestSimilar);
        $this->assertSame(-100_000.0, $contract->similarDiff);
    }

    public function testComparisonFallsBackToJitaWhenNoSimilarPublicContract(): void
    {
        $this->stubEsi(publicContractsFirstPage: [], publicContractsAllPages: []);

        $contract = $this->provideSingleContract();

        $this->assertSame(0, $contract->similarCount);
        $this->assertNull($contract->lowestSimilar);
        $this->assertSame(1_000_000.0, $contract->jitaValue);
        $this->assertSame(100_000.0, $contract->jitaDiff);
        $this->assertSame(10.0, $contract->jitaDiffPercent);
        $this->assertTrue($contract->isCompetitive);
    }

    // ===========================================
    // Public contracts comparison (availability, issue #78)
    // ===========================================

    public function testListFlagsPublicComparisonUnavailableWhenPublicContractsEsiCallFails(): void
    {
        $this->stubEsi(
            publicContractsFirstPage: [],
            publicContractsAllPages: [],
            publicContractsError: new \RuntimeException('ESI 503 on public contracts'),
        );

        $result = $this->provider->provide(new GetCollection());

        $this->assertFalse($result->publicComparisonAvailable);
        $this->assertSame(1, $result->total);
    }

    public function testContractSimilarComparisonIsUnknownNotZeroWhenPublicContractsEsiCallFails(): void
    {
        $this->stubEsi(
            publicContractsFirstPage: [],
            publicContractsAllPages: [],
            publicContractsError: new \RuntimeException('ESI 503 on public contracts'),
        );

        $contract = $this->provideSingleContract();

        // Unknown, not "no similar public contract".
        $this->assertNull($contract->similarCount);
        $this->assertNull($contract->lowestSimilar);
        $this->assertNull($contract->avgSimilar);
        $this->assertNull($contract->similarDiff);
        $this->assertNull($contract->similarDiffPercent);
    }

    public function testJitaComparisonStillComputedWhenPublicComparisonUnavailable(): void
    {
        $this->stubEsi(
            publicContractsFirstPage: [],
            publicContractsAllPages: [],
            publicContractsError: new \RuntimeException('ESI 503 on public contracts'),
        );

        $contract = $this->provideSingleContract();

        $this->assertSame(1_000_000.0, $contract->jitaValue);
        $this->assertSame(100_000.0, $contract->jitaDiff);
        $this->assertSame(10.0, $contract->jitaDiffPercent);
        // Jita-based verdict (+10 % <= 10 %), the list flag tells which reference was used.
        $this->assertTrue($contract->isCompetitive);
    }

    public function testListFlagsPublicComparisonAvailableWhenNoSimilarPublicContract(): void
    {
        $this->stubEsi(publicContractsFirstPage: [], publicContractsAllPages: []);

        $result = $this->provider->provide(new GetCollection());

        $this->assertTrue($result->publicComparisonAvailable);
        $this->assertCount(1, $result->contracts);
        $this->assertSame(0, $result->contracts[0]->similarCount);
    }

    public function testListFlagsPublicComparisonAvailableWhenSimilarPublicContractFound(): void
    {
        $publicContracts = [$this->publicContract(5001, 1_050_000.0, 100.0)];
        $this->stubEsi(publicContractsFirstPage: $publicContracts, publicContractsAllPages: $publicContracts);

        $result = $this->provider->provide(new GetCollection());

        $this->assertTrue($result->publicComparisonAvailable);
        $this->assertCount(1, $result->contracts);
        $this->assertSame(1, $result->contracts[0]->similarCount);
        $this->assertSame(1_050_000.0, $result->contracts[0]->lowestSimilar);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createProvider(): ContractCollectionProvider
    {
        return new ContractCollectionProvider(
            security: $this->security,
            esiClient: $this->esiClient,
            structureMarketService: $this->structureMarketService,
            jitaMarketService: $this->jitaMarketService,
            requestStack: $this->requestStack,
            logger: new NullLogger(),
            defaultMarketStructureId: self::DEFAULT_MARKET_STRUCTURE_ID,
            invTypeRepository: $this->invTypeRepository,
        );
    }

    /**
     * Mirrors InvTypeRepository::findByTypeIds(): types indexed by type ID, unknown IDs omitted.
     *
     * @param int[] $typeIds
     * @return array<int, InvType>
     */
    private function findSdeTypesByTypeIds(array $typeIds): array
    {
        $types = [];
        foreach ($typeIds as $typeId) {
            if (!isset($this->sdeTypeNames[$typeId])) {
                continue;
            }
            $type = new InvType();
            $type->setTypeId($typeId);
            $type->setTypeName($this->sdeTypeNames[$typeId]);
            $types[$typeId] = $type;
        }

        return $types;
    }

    private function provideSingleContract(): ContractResource
    {
        $result = $this->provider->provide(new GetCollection());

        $this->assertCount(1, $result->contracts);

        return $result->contracts[0];
    }

    /**
     * get() returns only the first page of a paginated endpoint; getPaginated() returns every page merged.
     *
     * @param list<array<string, mixed>> $publicContractsFirstPage
     * @param list<array<string, mixed>> $publicContractsAllPages
     * @param list<array<string, mixed>>|null $contractItems defaults to 1000 Tritanium
     */
    private function stubEsi(
        array $publicContractsFirstPage,
        array $publicContractsAllPages,
        ?array $contractItems = null,
        ?\Throwable $publicContractsError = null,
    ): void {
        $myContract = [
            'contract_id' => self::MY_CONTRACT_ID,
            'type' => 'item_exchange',
            'status' => 'outstanding',
            'availability' => 'public',
            'price' => 1_100_000.0,
            'volume' => 100.0,
            'issuer_id' => self::CHARACTER_ID,
            'date_issued' => '2026-10-01T12:00:00Z',
        ];
        $myContractItems = $contractItems ?? [
            ['type_id' => self::TRITANIUM_TYPE_ID, 'quantity' => 1000, 'is_included' => true],
        ];
        $publicContracts = static function (array $page) use ($publicContractsError): array {
            if ($publicContractsError !== null) {
                throw $publicContractsError;
            }

            return $page;
        };

        $this->esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => match ($endpoint) {
                self::CHARACTER_CONTRACTS_ENDPOINT => [$myContract],
                self::PUBLIC_CONTRACTS_ENDPOINT => $publicContracts($publicContractsAllPages),
                default => throw new \LogicException("Unexpected paginated ESI call: {$endpoint}"),
            },
        );
        $this->esiClient->method('get')->willReturnCallback(
            static fn (string $endpoint): array => match ($endpoint) {
                self::CHARACTER_CONTRACTS_ENDPOINT . self::MY_CONTRACT_ID . '/items/' => $myContractItems,
                self::PUBLIC_CONTRACTS_ENDPOINT => $publicContracts($publicContractsFirstPage),
                default => throw new \LogicException("Unexpected ESI call: {$endpoint}"),
            },
        );
    }

    /** @return array<string, mixed> */
    private function publicContract(
        int $contractId,
        float $price,
        float $volume,
        string $type = 'item_exchange',
        string $status = 'outstanding',
    ): array {
        return [
            'contract_id' => $contractId,
            'type' => $type,
            'status' => $status,
            'price' => $price,
            'volume' => $volume,
        ];
    }

    private function createUserWithMainCharacter(): User
    {
        $character = new Character();
        $character->setEveCharacterId(self::CHARACTER_ID);
        $character->setEveToken(new EveToken());

        $user = new User();
        $user->setMainCharacter($character);

        return $user;
    }
}
