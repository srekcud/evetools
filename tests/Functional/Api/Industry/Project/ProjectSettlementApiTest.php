<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectTransferReceipt;
use App\Entity\User;
use App\Enum\SaleChannel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-324')]
final class ProjectSettlementApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const float OWNER_TO_MEMBER_ISK = 277_500.0;
    private const float MEMBER_TO_OWNER_ISK = 2_497_500.0;
    private const float FINAL_MARGIN_PERCENT = 400.0;
    private const float CHEAPER_ISHTAR_UNIT_PRICE = 1_400_000.0;
    private const float OWNER_TO_MEMBER_CONTRACT_ISK = 300_000.0;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testRecipientReadsTheReferenceTheSingleTransferAndTheChecksBeforeClosing(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        self::assertResponseStatusCodeSame(200);
        $settlement = $this->responseJson();
        self::assertSame($this->expectedReference(), $settlement['reference']);
        self::assertNull($settlement['transfersUnavailableReason']);
        self::assertSame([$this->ownerTransferTo()], $this->withoutReceivedAt($settlement['transfers']));
        self::assertEqualsWithDelta(0.0, $settlement['roundingGap'], 0.0001);
        self::assertEqualsWithDelta(self::FINAL_MARGIN_PERCENT, $settlement['checks']['marginPercent'], 0.0001);
        self::assertSame(
            ['pendingContributionCount' => 1, 'unsoldProducts' => [], 'transfersTotal' => 1, 'transfersReceived' => 0],
            $this->withoutMargin($settlement['checks']),
        );
    }

    public function testPayerSeesTheTransferButCannotConfirmIt(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->settlementUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [false],
            array_column($this->responseJson()['transfers'], 'canConfirm'),
        );
    }

    public function testPendingMemberAndOutsiderAreRefused(): void
    {
        foreach ([$this->pendingMember, $this->outsider] as $refused) {
            $this->actAs($refused);
            $this->request('GET', $this->settlementUri());

            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testRecipientTicksTheTransferAndTheChecksCountIt(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), ['received' => true]);

        self::assertResponseStatusCodeSame(200);
        $settlement = $this->responseJson();
        self::assertTrue($settlement['transfers'][0]['received']);
        self::assertNotNull($settlement['transfers'][0]['receivedAt']);
        self::assertSame([1, 1], [$settlement['checks']['transfersTotal'], $settlement['checks']['transfersReceived']]);
        self::assertSame(1, $this->em->getRepository(IndustryProjectTransferReceipt::class)->count([]));
    }

    public function testPayerCannotTickTheTransferTheyOwe(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), ['received' => true]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(IndustryProjectTransferReceipt::class)->count([]));
    }

    public function testRecipientUnticksTheTransferAndTheReceiptIsDeleted(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), ['received' => true]);
        $this->actAs($this->member);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), ['received' => false]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([false, 0], [$this->responseJson()['transfers'][0]['received'], $this->responseJson()['checks']['transfersReceived']]);
        self::assertSame(0, $this->em->getRepository(IndustryProjectTransferReceipt::class)->count([]));
    }

    public function testTransferThatIsNotInThePlanIsNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->receiptUri($this->memberMembership, $this->ownerMembership), ['received' => true]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBodyWithoutABooleanIsRejected(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(IndustryProjectTransferReceipt::class)->count([]));
    }

    public function testSellerCharacterOfTheSaleHoldsTheRevenueInsteadOfTheRecorder(): void
    {
        $this->ishtarSale->setSeller($this->member->getMainCharacter());
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        self::assertSame(
            [[$this->idOf($this->memberMembership), $this->idOf($this->ownerMembership), self::MEMBER_TO_OWNER_ISK]],
            array_map(
                static fn (array $transfer): array => [$transfer['fromParticipantId'], $transfer['toParticipantId'], $transfer['amount']],
                $this->responseJson()['transfers'],
            ),
        );
    }

    public function testReceiptNoLongerCountsWhenTheSalesChangeTheAmountOfTheTransfer(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), ['received' => true]);
        self::assertResponseStatusCodeSame(200);

        $this->ishtarSale->setUnitPrice(self::CHEAPER_ISHTAR_UNIT_PRICE)->setTotalPrice(2 * self::CHEAPER_ISHTAR_UNIT_PRICE);
        $this->em->flush();
        $this->em->clear();
        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([false], array_column($settlement['transfers'], 'received'));
        self::assertSame(0, $settlement['checks']['transfersReceived']);
    }

    public function testWithoutAnySaleTransfersAreAwaitingSalesAndEveryProductIsUnsold(): void
    {
        $this->em->remove($this->ishtarSale);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([null, 'awaiting_sales'], [$settlement['transfers'], $settlement['transfersUnavailableReason']]);
        self::assertSame(
            [['typeId' => self::ISHTAR_TYPE_ID, 'typeName' => 'Ishtar', 'quantity' => 2]],
            $settlement['checks']['unsoldProducts'],
        );
        self::assertNull($settlement['checks']['transfersTotal']);
        self::assertNull($settlement['checks']['transfersReceived']);
    }

    public function testUnsoldProductsListTheRemainderPerItemAndHoldBackTheTransfers(): void
    {
        $this->ishtarSale->setQuantity(1)->setTotalPrice(1_500_000.0);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([null, 'unsold_products'], [$settlement['transfers'], $settlement['transfersUnavailableReason']]);
        self::assertSame(
            [['typeId' => self::ISHTAR_TYPE_ID, 'typeName' => 'Ishtar', 'quantity' => 1]],
            $settlement['checks']['unsoldProducts'],
        );
    }

    public function testUnknownFeesLeaveTheTransfersUnknownNeverZero(): void
    {
        $this->ishtarBatch->setBrokerFeePercent(null);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([null, 'unknown_fees'], [$settlement['transfers'], $settlement['transfersUnavailableReason']]);
        self::assertNull($settlement['checks']['marginPercent']);
    }

    public function testSaleHeldByAnOutsiderLeavesTheTransfersUnknown(): void
    {
        $this->ishtarSale->setRecordedBy($this->ownerCorpmate);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([null, 'unknown_seller'], [$settlement['transfers'], $settlement['transfersUnavailableReason']]);
    }

    public function testContractSaleIsHeldWithoutAnyFeeSoTheTransferFollowsTheFullPrice(): void
    {
        $this->ishtarSale->setChannel(SaleChannel::Contract);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([self::OWNER_TO_MEMBER_CONTRACT_ISK], array_column($settlement['transfers'], 'amount'));
        self::assertEqualsWithDelta(0.0, $settlement['roundingGap'], 0.0001);
    }

    public function testUnknownFeesDoNotHoldBackTheTransfersOfAProjectSoldByContractOnly(): void
    {
        $this->ishtarBatch->setBrokerFeePercent(null);
        $this->ishtarSale->setChannel(SaleChannel::Contract);
        $this->em->flush();
        $this->em->clear();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertNull($settlement['transfersUnavailableReason']);
        self::assertSame([self::OWNER_TO_MEMBER_CONTRACT_ISK], array_column($settlement['transfers'], 'amount'));
    }

    public function testDonationFromThePayerCarryingTheReferenceAndTheAmountTicksTheTransferWithoutAnyReceipt(): void
    {
        $this->persistDonation($this->member, $this->owner, self::OWNER_TO_MEMBER_ISK, strtolower($this->expectedReference()).' payout', 9_501);
        $this->flushAndDetach();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([true, true], [$settlement['transfers'][0]['received'], $settlement['transfers'][0]['journalVerified']]);
        self::assertSame('2026-11-10T18:00:00+00:00', $settlement['transfers'][0]['receivedAt']);
        self::assertSame([1, 1], [$settlement['checks']['transfersTotal'], $settlement['checks']['transfersReceived']]);
        self::assertSame(0, $this->em->getRepository(IndustryProjectTransferReceipt::class)->count([]));
    }

    public function testDonationsAddUpToTheAmountOfTheTransfer(): void
    {
        $this->persistDonation($this->member, $this->owner, 100_000.0, $this->expectedReference(), 9_502);
        $this->persistDonation($this->member, $this->owner, 177_500.0, $this->expectedReference(), 9_503);
        $this->flushAndDetach();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        self::assertSame([true], array_column($this->responseJson()['transfers'], 'journalVerified'));
    }

    public function testDonationThatDoesNotMatchTheTransferLeavesItUnverified(): void
    {
        $this->persistDonation($this->member, $this->owner, 277_499.99, $this->expectedReference(), 9_504);
        $this->persistDonation($this->member, $this->owner, self::OWNER_TO_MEMBER_ISK, 'IP-000000', 9_505);
        $this->persistDonation($this->member, $this->outsider, self::OWNER_TO_MEMBER_ISK, $this->expectedReference(), 9_506);
        $this->persistDonation($this->owner, $this->owner, self::OWNER_TO_MEMBER_ISK, $this->expectedReference(), 9_507);
        $this->flushAndDetach();

        $this->actAs($this->member);
        $this->request('GET', $this->settlementUri());

        $settlement = $this->responseJson();
        self::assertSame([false, false], [$settlement['transfers'][0]['received'], $settlement['transfers'][0]['journalVerified']]);
        self::assertSame(0, $settlement['checks']['transfersReceived']);
    }

    public function testRecipientCannotUntickATransferTheJournalVerified(): void
    {
        $this->persistDonation($this->member, $this->owner, self::OWNER_TO_MEMBER_ISK, $this->expectedReference(), 9_508);
        $this->flushAndDetach();

        $this->actAs($this->member);
        $this->request('POST', $this->receiptUri($this->ownerMembership, $this->memberMembership), ['received' => false]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([true, true], [$this->responseJson()['transfers'][0]['received'], $this->responseJson()['transfers'][0]['journalVerified']]);
    }

    private function settlementUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/settlement';
    }

    private function persistDonation(User $recipient, User $sender, float $amount, string $reason, int $journalEntryId): void
    {
        $this->em->persist(
            (new CachedWalletJournalEntry())
                ->setCharacter($recipient->getMainCharacter())
                ->setJournalEntryId($journalEntryId)
                ->setRefType(CachedWalletJournalEntry::PLAYER_DONATION)
                ->setAmount($amount)
                ->setDate(new \DateTimeImmutable('2026-11-10T18:00:00+00:00'))
                ->setReason($reason)
                ->setFirstPartyId($sender->getMainCharacter()->getEveCharacterId()),
        );
    }

    private function flushAndDetach(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    private function receiptUri(IndustryProjectParticipant $from, IndustryProjectParticipant $to): string
    {
        return $this->projectUri($this->ishtarBatch).'/transfers/'.$this->idOf($from).'/'.$this->idOf($to).'/receipt';
    }

    private function expectedReference(): string
    {
        return 'IP-'.strtoupper(substr(str_replace('-', '', $this->ishtarBatch->getId()->toRfc4122()), 0, 6));
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerTransferTo(): array
    {
        return [
            'fromParticipantId' => $this->idOf($this->ownerMembership),
            'fromName' => 'Aria Solberg',
            'toParticipantId' => $this->idOf($this->memberMembership),
            'toName' => 'Brann Holt',
            'amount' => self::OWNER_TO_MEMBER_ISK,
            'received' => false,
            'journalVerified' => false,
            'canConfirm' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $transfers
     *
     * @return list<array<string, mixed>>
     */
    private function withoutReceivedAt(array $transfers): array
    {
        return array_map(static function (array $transfer): array {
            unset($transfer['receivedAt']);

            return $transfer;
        }, $transfers);
    }

    /**
     * @param array<string, mixed> $checks
     *
     * @return array<string, mixed>
     */
    private function withoutMargin(array $checks): array
    {
        unset($checks['marginPercent']);

        return $checks;
    }
}
