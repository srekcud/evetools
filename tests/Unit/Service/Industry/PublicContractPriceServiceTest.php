<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Message\SyncPublicContracts;
use App\MessageHandler\SyncPublicContractsHandler;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\Industry\PublicContractPriceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(PublicContractPriceService::class)]
#[Group('issue-217')]
final class PublicContractPriceServiceTest extends TestCase
{
    private const THE_FORGE_CONTRACTS_PATH = '/contracts/public/10000002/';
    private const TRITANIUM = 34;
    private const PYERITE = 35;

    private ArrayAdapter $publicContractsCache;

    private PublicContractPriceService $publicContractPriceService;

    protected function setUp(): void
    {
        $this->publicContractsCache = new ArrayAdapter();
        $this->publicContractPriceService = new PublicContractPriceService($this->publicContractsCache);
    }

    public function testContractCountAndLowestUnitPriceAreUnknownBeforeAnySync(): void
    {
        $this->assertNull($this->publicContractPriceService->getContractCount(self::TRITANIUM));
        $this->assertNull($this->publicContractPriceService->getLowestUnitPrice(self::TRITANIUM));
    }

    public function testIndexedTypeHasItsContractCountAndATypeAbsentFromTheIndexHasNoContract(): void
    {
        $this->syncTritaniumContracts();

        $this->assertSame(2, $this->publicContractPriceService->getContractCount(self::TRITANIUM));
        $this->assertSame(5_000.0, $this->publicContractPriceService->getLowestUnitPrice(self::TRITANIUM));
        $this->assertSame(0, $this->publicContractPriceService->getContractCount(self::PYERITE));
        $this->assertNull($this->publicContractPriceService->getLowestUnitPrice(self::PYERITE));
    }

    public function testIndexWithoutItsMetaIsUnknownEvenWhenATypeEntryRemains(): void
    {
        $this->syncTritaniumContracts();
        $this->publicContractsCache->deleteItem('public_contract_prices_meta');

        $this->assertNull($this->publicContractPriceService->getContractCount(self::TRITANIUM));
        $this->assertNull($this->publicContractPriceService->getLowestUnitPrice(self::TRITANIUM));
        $this->assertNull($this->publicContractPriceService->getContractCount(self::PYERITE));
    }

    private function syncTritaniumContracts(): void
    {
        $itemsByContractId = [
            20001 => [['type_id' => self::TRITANIUM, 'quantity' => 50, 'is_included' => true]],
            20003 => [['type_id' => self::TRITANIUM, 'quantity' => 60, 'is_included' => true]],
        ];
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(
            fn (string $endpoint): array => match ($endpoint) {
                self::THE_FORGE_CONTRACTS_PATH => [
                    $this->itemExchangeContract(20001, 500_000.0),
                    $this->itemExchangeContract(20003, 300_000.0),
                ],
                default => throw new \LogicException('Unexpected ESI endpoint: '.$endpoint),
            },
        );
        $esiClient->method('getBatch')->willReturnCallback(
            static fn (array $endpoints): array => array_intersect_key($itemsByContractId, $endpoints),
        );

        $handler = new SyncPublicContractsHandler(
            esiClient: $esiClient,
            cache: $this->publicContractsCache,
            logger: new NullLogger(),
            syncTracker: $this->createStub(SyncTracker::class),
        );
        $handler(new SyncPublicContracts());
    }

    private function itemExchangeContract(int $contractId, float $price): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'price' => $price,
            'date_expired' => (new \DateTimeImmutable('+7 days'))->format('c'),
            'date_issued' => (new \DateTimeImmutable('-1 day'))->format('c'),
        ];
    }
}
