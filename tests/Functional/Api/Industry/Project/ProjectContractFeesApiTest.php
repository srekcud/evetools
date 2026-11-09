<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\IndustryProjectProduct;
use App\Enum\SaleChannel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-500')]
final class ProjectContractFeesApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int CONTRACT_ID = 601_001;
    private const float CONTRACT_PRICE = 3_000_000.0;
    private const float BROKER_FEE = 30_000.0;
    private const float SALES_TAX = 108_000.0;
    private const float NET_REVENUE = 2_862_000.0;
    private const float OWNER_TO_MEMBER = 286_200.0;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->ishtarBatch->addItem((new IndustryProjectProduct())->setTypeId(self::ISHTAR_TYPE_ID)->setTypeName('Ishtar')->setRuns(self::ISHTAR_RUNS));
        $this->em->remove($this->ishtarSale);
        $this->em->flush();
        $sale = $this->addSale($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 2, self::CONTRACT_PRICE / 2, 'Contract', '2026-11-09T18:00:00+00:00')
            ->setChannel(SaleChannel::Contract)
            ->setContractId(self::CONTRACT_ID)
            ->setSeller($this->owner->getMainCharacter());
        $this->em->persist($sale);
        $this->em->flush();
    }

    public function testDistributionDeductsTheRealFeesOfTheContractFromTheNetRevenue(): void
    {
        $this->storeJournalFees();

        $distribution = $this->distribution();

        self::assertEqualsWithDelta(self::BROKER_FEE + self::SALES_TAX, $distribution['contractFees'], 0.0001);
        self::assertEqualsWithDelta(self::NET_REVENUE, $distribution['netRevenue'], 0.0001);
        self::assertEqualsWithDelta(self::CONTRACT_PRICE, $distribution['contractRevenue'], 0.0001);
    }

    public function testFeesOfTheContractAreUnknownNeverZeroWhileTheJournalHasNoLineForIt(): void
    {
        $distribution = $this->distribution();

        self::assertNull($distribution['contractFees'] ?? null);
        self::assertNull($distribution['netRevenue'] ?? null);
        self::assertNull($distribution['marginPercent'] ?? null);
    }

    public function testOnlyOneOfTheTwoFeesInTheJournalIsStillAKnownSumOfWhatWasRead(): void
    {
        $this->storeJournalFee('contract_sales_tax', -self::SALES_TAX);

        self::assertEqualsWithDelta(self::SALES_TAX, $this->distribution()['contractFees'], 0.0001);
    }

    public function testCashHeldByTheSellerOfTheContractIsThePriceMinusItsFees(): void
    {
        $this->storeJournalFees();

        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/settlement');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::OWNER_TO_MEMBER], array_column($this->responseJson()['transfers'], 'amount'));
    }

    public function testTransfersAreUnavailableWithUnknownFeesWhileTheJournalHasNoLineForTheContract(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/settlement');

        $settlement = $this->responseJson();
        self::assertSame([null, 'unknown_fees'], [$settlement['transfers'], $settlement['transfersUnavailableReason']]);
    }

    public function testContractSaleTypedByHandKeepsItsFullPriceAndNeedsNoJournal(): void
    {
        $sale = $this->ishtarBatch->getSales()->first();
        $sale->setContractId(null);
        $this->em->flush();

        $distribution = $this->distribution();

        self::assertEqualsWithDelta(self::CONTRACT_PRICE, $distribution['netRevenue'], 0.0001);
        self::assertEqualsWithDelta(0.0, $distribution['contractFees'], 0.0001);
    }

    /**
     * @return array<string, mixed>
     */
    private function distribution(): array
    {
        $this->em->clear();
        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/distribution');
        self::assertResponseStatusCodeSame(200);

        return $this->responseJson();
    }

    private function storeJournalFees(): void
    {
        $this->storeJournalFee('contract_brokers_fee', -self::BROKER_FEE);
        $this->storeJournalFee('contract_sales_tax', -self::SALES_TAX);
    }

    private function storeJournalFee(string $refType, float $amount): void
    {
        $this->em->persist((new CachedWalletJournalEntry())
            ->setCharacter($this->owner->getMainCharacter())
            ->setJournalEntryId(random_int(1, PHP_INT_MAX))
            ->setRefType($refType)
            ->setAmount($amount)
            ->setDate(new \DateTimeImmutable('2026-11-09T18:00:00+00:00'))
            ->setContextId(self::CONTRACT_ID)
            ->setContextIdType('contract_id'));
        $this->em->flush();
    }
}
