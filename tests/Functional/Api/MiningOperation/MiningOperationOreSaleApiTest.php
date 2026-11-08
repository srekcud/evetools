<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\Sde\InvType;
use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-314')]
final class MiningOperationOreSaleApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;

    private const float VALUE_DELTA = 1e-9;

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
    }

    public function testAParticipantGetsTheCompressedEquivalentOfHisMinedOreWithAnUnknownTotalWhenOneTypeHasNoPrice(): void
    {
        $this->closingE1();
        $this->as($this->aria);
        $this->compressedVariantsKeepOneUnitPerBatch();
        $this->pricingOnlyCompressedVeldspar();

        $this->request('GET', $this->oreSaleUri());
        $sale = $this->responseJson();

        self::assertResponseStatusCodeSame(200);
        self::assertSame((string) $this->spodumain->getId(), $sale['operationId']);
        self::assertSame([self::COMPRESSED_VELDSPAR, self::COMPRESSED_BITUMENS], array_column($sale['rows'], 'typeId'));
        self::assertSame([1_000, 33], array_column($sale['rows'], 'quantity'));
        self::assertEqualsWithDelta(19_610.0, $sale['rows'][0]['totalValue'], self::VALUE_DELTA);
        self::assertNull($sale['rows'][1]['totalValue']);
        self::assertNull($sale['totals']['totalValue']);
        self::assertSame([self::COMPRESSED_BITUMENS], $sale['totals']['missingPriceTypeIds']);
        self::assertSame([], $sale['unconvertedTypes']);
    }

    public function testAParticipantWhoseStartLedgerCouldNotBeReadGetsAConflict(): void
    {
        $this->closingE1(unreadStarts: ['Brann Holt']);
        $this->as($this->brann);

        $this->request('GET', $this->oreSaleUri());

        self::assertResponseStatusCodeSame(409);
    }

    public function testAStrangerToTheOperationIsForbidden(): void
    {
        $this->closingE1();
        $this->as($this->dorn);

        $this->request('GET', $this->oreSaleUri());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $this->closingE1();
        self::ensureKernelShutdown();
        $this->client = self::createClient();

        $this->request('GET', $this->oreSaleUri());

        self::assertResponseStatusCodeSame(401);
    }

    private function oreSaleUri(): string
    {
        return sprintf('/api/mining-operations/%s/ore-sale', $this->spodumain->getId());
    }

    private function compressedVariantsKeepOneUnitPerBatch(): void
    {
        foreach ([self::COMPRESSED_VELDSPAR, self::COMPRESSED_BITUMENS] as $typeId) {
            $type = $this->em->find(InvType::class, $typeId);
            \assert($type instanceof InvType);
            $type->setPortionSize(1);
        }
        $this->em->flush();
    }

    private function pricingOnlyCompressedVeldspar(): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getPricesWithFallback')->willReturnCallback(fn (array $typeIds): array => $this->pricesOf($typeIds, [self::COMPRESSED_VELDSPAR => 19.61]));
        $jitaMarket->method('getBuyPricesWithFallback')->willReturnCallback(fn (array $typeIds): array => $this->pricesOf($typeIds, []));
        $jitaMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::JITA_SYNCED_AT));
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
    }
}
