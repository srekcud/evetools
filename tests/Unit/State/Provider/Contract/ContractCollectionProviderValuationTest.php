<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Contract;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Contract\ContractItemResource;
use App\ApiResource\Contract\ContractListResource;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(ContractCollectionProvider::class)]
class ContractCollectionProviderValuationTest extends TestCase
{
    private const int CHARACTER_ID = 90000001;
    private const int OTHER_CHARACTER_ID = 90000777;
    private const int DEFAULT_MARKET_STRUCTURE_ID = 1035466617946;
    private const string CHARACTER_CONTRACTS_ENDPOINT = '/characters/90000001/contracts/';
    private const string PUBLIC_CONTRACTS_ENDPOINT = '/contracts/public/' . EveConstants::THE_FORGE_REGION_ID . '/';

    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int TYPE_ID_BREAKING_THE_SDE = 999_999_001;

    private array $characterContracts = [];
    private array $itemsByContractId = [];
    private array $unreadableContractIds = [];
    private array $publicContracts = [];
    private array $jitaPrices = [
        self::TRITANIUM_TYPE_ID => 4.25,
        self::PYERITE_TYPE_ID => 9.5,
    ];
    private array $structurePrices = [];
    private ?array $query = [];
    private ?User $user;

    protected function setUp(): void
    {
        $character = new Character();
        $character->setEveCharacterId(self::CHARACTER_ID);
        $character->setEveToken(new EveToken());
        $this->user = new User();
        $this->user->setMainCharacter($character);
    }

    public function testListWithoutAuthenticatedUserIsUnauthorized(): void
    {
        $this->user = null;

        $this->assertSame(401, $this->httpStatusCodeOfList());
    }

    public function testListWithoutMainCharacterIsForbidden(): void
    {
        $this->user = new User();

        $this->assertSame(403, $this->httpStatusCodeOfList());
    }

    public function testListWithoutEveTokenIsForbidden(): void
    {
        $character = new Character();
        $character->setEveCharacterId(self::CHARACTER_ID);
        $this->user = new User();
        $this->user->setMainCharacter($character);

        $this->assertSame(403, $this->httpStatusCodeOfList());
    }

    #[DataProvider('contractFilters')]
    public function testListKeepsOnlyItemExchangeContractsMatchingTheFilters(?array $query, array $expectedContractIds): void
    {
        $this->givenItemExchange(2001, status: 'outstanding', availability: 'public');
        $this->givenItemExchange(2002, status: 'outstanding', availability: 'personal');
        $this->givenItemExchange(2003, status: 'finished', availability: 'public');
        $this->givenItemExchange(2004, status: 'outstanding', availability: null);
        $this->givenItemExchange(2005, status: null, availability: 'public');
        $this->characterContracts[] = $this->contract(2006, type: 'courier', status: 'outstanding', availability: 'public');
        $this->query = $query;

        $list = $this->listContracts();

        $this->assertSame($expectedContractIds, $this->sortedContractIdsOf($list));
        $this->assertSame([], $list->unknownContractIds);
    }

    public static function contractFilters(): iterable
    {
        yield 'no request: outstanding only' => [null, [2001, 2002, 2004]];
        yield 'no filter: outstanding only' => [[], [2001, 2002, 2004]];
        yield 'finished' => [['status' => 'finished'], [2003]];
        yield 'every status' => [['status' => 'all'], [2001, 2002, 2003, 2004, 2005]];
        yield 'public availability' => [['availability' => 'public'], [2001]];
        yield 'personal availability includes contracts without availability' => [['availability' => 'personal'], [2002, 2004]];
        yield 'every availability' => [['availability' => 'all'], [2001, 2002, 2004]];
        yield 'every status and public availability' => [['status' => 'all', 'availability' => 'public'], [2001, 2003, 2005]];
    }

    public function testContractsAreListedFromTheMostRecentlyIssued(): void
    {
        $this->givenItemExchange(2101, dateIssued: '2026-10-02T08:00:00Z');
        $this->givenItemExchange(2102, dateIssued: '2026-10-05T08:00:00Z');
        $this->givenItemExchange(2103, dateIssued: '2026-09-28T08:00:00Z');

        $contractIds = array_map(static fn (ContractResource $contract): int => $contract->contractId, $this->listContracts()->contracts);

        $this->assertSame([2102, 2101, 2103], $contractIds);
    }

    public function testReadableContractAfterAnUnreadableOneIsStillListed(): void
    {
        $this->givenItemExchange(2201);
        $this->givenItemExchange(2202);
        $this->unreadableContractIds = [2201];

        $list = $this->listContracts();

        $this->assertSame([2202], $this->sortedContractIdsOf($list));
        $this->assertSame([2201], $list->unknownContractIds);
    }

    public function testContractProcessedAfterAFailingOneIsStillListed(): void
    {
        $this->givenItemExchange(2301, items: [$this->item(self::TYPE_ID_BREAKING_THE_SDE, 5)]);
        $this->givenItemExchange(2302);

        $list = $this->listContracts();

        $this->assertSame([2302], $this->sortedContractIdsOf($list));
        $this->assertSame([2301], $list->unknownContractIds);
    }

    public function testEveryContractFieldIsCopiedFromEsi(): void
    {
        $this->characterContracts[] = [
            'contract_id' => 2401,
            'type' => 'item_exchange',
            'status' => 'outstanding',
            'availability' => 'corporation',
            'title' => 'Minerals for the Raitaru',
            'price' => 7_250.0,
            'reward' => 125.5,
            'volume' => 42.0,
            'date_issued' => '2026-10-03T10:00:00Z',
            'date_expired' => '2026-10-17T10:00:00Z',
            'date_completed' => '2026-10-04T09:30:00Z',
            'issuer_id' => self::OTHER_CHARACTER_ID,
            'assignee_id' => 98000123,
            'acceptor_id' => self::CHARACTER_ID,
            'for_corporation' => true,
        ];
        $this->itemsByContractId[2401] = [$this->item(self::TRITANIUM_TYPE_ID, 1000)];
        $this->query = ['availability' => 'corporation'];

        $contract = $this->singleContract();

        $this->assertSame(
            ['outstanding', 'corporation', 'Minerals for the Raitaru', 7_250.0, 125.5, 42.0, '2026-10-03T10:00:00Z', '2026-10-17T10:00:00Z', '2026-10-04T09:30:00Z', self::OTHER_CHARACTER_ID, 98000123, self::CHARACTER_ID, true, false],
            [$contract->status, $contract->availability, $contract->title, $contract->price, $contract->reward, $contract->volume, $contract->dateIssued, $contract->dateExpired, $contract->dateCompleted, $contract->issuerId, $contract->assigneeId, $contract->acceptorId, $contract->forCorporation, $contract->isSeller],
        );
    }

    public function testContractIssuedByTheMainCharacterIsASale(): void
    {
        $this->givenItemExchange(2501);

        $this->assertTrue($this->singleContract()->isSeller);
    }

    public function testIncludedItemsAreValuedAtJitaAndAtTheDefaultMarketStructure(): void
    {
        $this->structurePrices = [self::TRITANIUM_TYPE_ID => 4.5, self::PYERITE_TYPE_ID => 11.0];
        $this->givenItemExchange(2601, price: 12_345.0, items: [
            $this->item(self::TRITANIUM_TYPE_ID, 1000),
            $this->item(self::PYERITE_TYPE_ID, 500),
            $this->item(self::MEXALLON_TYPE_ID, 300, isIncluded: false),
        ]);

        $contract = $this->singleContract();

        $this->assertSame(
            [[self::TRITANIUM_TYPE_ID, 'Tritanium', 1000, 4.25, 4250.0, 4.5, 4500.0], [self::PYERITE_TYPE_ID, 'Pyerite', 500, 9.5, 4750.0, 11.0, 5500.0]],
            array_map(
                static fn (ContractItemResource $item): array => [$item->typeId, $item->typeName, $item->quantity, $item->jitaPrice, $item->jitaValue, $item->delvePrice, $item->delveValue],
                $contract->items,
            ),
        );
        $this->assertSame(2, $contract->itemCount);
        $this->assertSame([9_000.0, 3_345.0, 37.166666666666664], [$contract->jitaValue, $contract->jitaDiff, $contract->jitaDiffPercent]);
        $this->assertSame([10_000.0, 2_345.0, 23.45], [$contract->delveValue, $contract->delveDiff, $contract->delveDiffPercent]);
    }

    public function testContractWithoutAnyMarketPriceHasNoValueNorComparison(): void
    {
        $this->givenItemExchange(2701, items: [$this->item(self::MEXALLON_TYPE_ID, 300)]);

        $contract = $this->singleContract();

        $this->assertSame(
            [null, null, null, null, null, null, null],
            [$contract->jitaValue, $contract->jitaDiff, $contract->jitaDiffPercent, $contract->delveValue, $contract->delveDiff, $contract->delveDiffPercent, $contract->isCompetitive],
        );
    }

    public function testPublicContractsWithinTwoPercentOfTheVolumeAreSimilar(): void
    {
        $this->publicContracts = [
            $this->publicContract(3001, 1_010_000.0, 97.9),
            $this->publicContract(3002, 1_020_000.0, 98.0),
            $this->publicContract(3003, 1_030_000.0, 102.0),
            $this->publicContract(3004, 990_000.0, 102.1),
        ];
        $this->givenItemExchange(2801, price: 1_100_000.0, volume: 100.0);

        $contract = $this->singleContract();

        $this->assertSame([2, 1_020_000.0, 1_025_000.0], [$contract->similarCount, $contract->lowestSimilar, $contract->avgSimilar]);
    }

    public function testContractPricedFivePercentAboveTheLowestSimilarIsCompetitive(): void
    {
        $this->publicContracts = [$this->publicContract(3101, 1_000_000.0, 100.0)];
        $this->givenItemExchange(2901, price: 1_050_000.0, volume: 100.0);

        $contract = $this->singleContract();

        $this->assertSame([5.0, true], [$contract->similarDiffPercent, $contract->isCompetitive]);
    }

    public function testContractWithoutVolumeIsNotComparedToPublicContracts(): void
    {
        $this->publicContracts = [$this->publicContract(3201, 800_000.0, 0.0)];
        $this->givenItemExchange(3001, price: 900_000.0, volume: 0.0);

        $this->assertSame(0, $this->singleContract()->similarCount);
    }

    public function testFreeSimilarPublicContractGivesNoPercentageAndFallsBackToJitaForCompetitiveness(): void
    {
        $this->publicContracts = [$this->publicContract(3301, 0.0, 100.0)];
        $this->givenItemExchange(3101, price: 4_675.0, volume: 100.0, items: [$this->item(self::TRITANIUM_TYPE_ID, 1000)]);

        $contract = $this->singleContract();

        $this->assertSame(
            [0.0, 4_675.0, null, 10.0, true],
            [$contract->lowestSimilar, $contract->similarDiff, $contract->similarDiffPercent, $contract->jitaDiffPercent, $contract->isCompetitive],
        );
    }

    private function givenItemExchange(
        int $contractId,
        ?string $status = 'outstanding',
        ?string $availability = 'public',
        string $dateIssued = '2026-10-01T12:00:00Z',
        float $price = 1_000_000.0,
        float $volume = 50.0,
        ?array $items = null,
    ): void {
        $this->characterContracts[] = $this->contract($contractId, 'item_exchange', $status, $availability, $dateIssued, $price, $volume);
        $this->itemsByContractId[$contractId] = $items ?? [$this->item(self::TRITANIUM_TYPE_ID, 1000)];
    }

    private function contract(
        int $contractId,
        string $type,
        ?string $status,
        ?string $availability,
        string $dateIssued = '2026-10-01T12:00:00Z',
        float $price = 1_000_000.0,
        float $volume = 50.0,
    ): array {
        $contract = [
            'contract_id' => $contractId,
            'type' => $type,
            'price' => $price,
            'volume' => $volume,
            'issuer_id' => self::CHARACTER_ID,
            'date_issued' => $dateIssued,
        ];
        if ($status !== null) {
            $contract['status'] = $status;
        }
        if ($availability !== null) {
            $contract['availability'] = $availability;
        }

        return $contract;
    }

    private function item(int $typeId, int $quantity, bool $isIncluded = true): array
    {
        return ['type_id' => $typeId, 'quantity' => $quantity, 'is_included' => $isIncluded];
    }

    private function publicContract(int $contractId, float $price, float $volume): array
    {
        return ['contract_id' => $contractId, 'type' => 'item_exchange', 'status' => 'outstanding', 'price' => $price, 'volume' => $volume];
    }

    private function singleContract(): ContractResource
    {
        $list = $this->listContracts();
        $this->assertCount(1, $list->contracts);

        return $list->contracts[0];
    }

    private function sortedContractIdsOf(ContractListResource $list): array
    {
        $contractIds = array_map(static fn (ContractResource $contract): int => $contract->contractId, $list->contracts);
        sort($contractIds);

        return $contractIds;
    }

    private function httpStatusCodeOfList(): ?int
    {
        try {
            $this->listContracts();
        } catch (HttpExceptionInterface $e) {
            return $e->getStatusCode();
        } catch (\Throwable) {
            return null;
        }

        return 200;
    }

    private function listContracts(): ContractListResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(
            fn (string $endpoint): array => match ($endpoint) {
                self::CHARACTER_CONTRACTS_ENDPOINT => $this->characterContracts,
                self::PUBLIC_CONTRACTS_ENDPOINT => $this->publicContracts,
                default => throw new \LogicException('Unexpected paginated ESI call ' . $endpoint),
            },
        );
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            foreach ($this->itemsByContractId as $contractId => $items) {
                if ($endpoint === self::CHARACTER_CONTRACTS_ENDPOINT . $contractId . '/items/') {
                    if (in_array($contractId, $this->unreadableContractIds, true)) {
                        throw new \RuntimeException('ESI 502 on ' . $endpoint);
                    }

                    return $items;
                }
            }

            throw new \LogicException('Unexpected ESI call ' . $endpoint);
        });

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->jitaPrices, array_flip($typeIds)),
        );

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnCallback(
            fn (int $structureId, int $typeId): ?float => $structureId === self::DEFAULT_MARKET_STRUCTURE_ID ? ($this->structurePrices[$typeId] ?? null) : null,
        );

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(static function (array $typeIds): array {
            $sdeTypeNames = [self::TRITANIUM_TYPE_ID => 'Tritanium', self::PYERITE_TYPE_ID => 'Pyerite', self::MEXALLON_TYPE_ID => 'Mexallon'];
            $types = [];
            foreach ($typeIds as $typeId) {
                if ($typeId === self::TYPE_ID_BREAKING_THE_SDE) {
                    throw new \RuntimeException('SDE lookup failed');
                }
                if (isset($sdeTypeNames[$typeId])) {
                    $type = new InvType();
                    $type->setTypeId($typeId);
                    $type->setTypeName($sdeTypeNames[$typeId]);
                    $types[$typeId] = $type;
                }
            }

            return $types;
        });

        $requestStack = new RequestStack();
        if ($this->query !== null) {
            $requestStack->push(new Request($this->query));
        }

        $provider = new ContractCollectionProvider(
            security: $security,
            esiClient: $esiClient,
            structureMarketService: $structureMarketService,
            jitaMarketService: $jitaMarketService,
            requestStack: $requestStack,
            logger: new NullLogger(),
            defaultMarketStructureId: self::DEFAULT_MARKET_STRUCTURE_ID,
            invTypeRepository: $invTypeRepository,
        );

        return $provider->provide(new GetCollection());
    }
}
