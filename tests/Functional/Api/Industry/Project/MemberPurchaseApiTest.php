<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-981')]
final class MemberPurchaseApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_STATION_ID = 60_003_760;
    private const int MEMBER_TRITANIUM_BUY_ID = 9_981_001;
    private const int OUTSIDER_TRITANIUM_BUY_ID = 9_981_002;
    private const int MEMBER_TRITANIUM_QUANTITY = 100;
    private const float MEMBER_TRITANIUM_UNIT_PRICE = 5.25;
    private const int OWNER_TRITANIUM_QUANTITY = 60;
    private const float OWNER_TRITANIUM_UNIT_PRICE = 4.5;
    private const string ONLY_BUYER_OR_OWNER = 'Only the buyer or the project owner can delete this purchase';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testMemberLinksHisWalletBuyAndHisProposalIsPendingAtThePaidPriceWithoutServingTheLine(): void
    {
        $transaction = $this->walletBuy($this->member->getMainCharacter(), self::MEMBER_TRITANIUM_BUY_ID);

        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->purchasesUri($this->tritaniumLine), ['transactionId' => $transaction]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->tritaniumLine), self::TRITANIUM_TYPE_ID, self::MEMBER_TRITANIUM_QUANTITY, 525.0, 'esi_wallet'],
            [$response['bomLineId'], $response['typeId'], $response['quantity'], $response['totalPrice'], $response['source']],
        );
        self::assertSame([$this->idOf($this->memberMembership)], $this->storedBuyerIdsOf($this->tritaniumLine));
        self::assertSame(
            [[$this->idOf($this->memberMembership), 100, 525.0, ContributionStatus::Pending, false]],
            $this->storedDerivedMaterialContributionsOf($this->tritaniumLine),
        );
        self::assertSame(0, $this->storedFulfilledQuantityOf($this->tritaniumLine));
    }

    public function testMemberLinkingTheSameWalletBuyTwiceIsAConflict(): void
    {
        $transaction = $this->walletBuy($this->member->getMainCharacter(), self::MEMBER_TRITANIUM_BUY_ID);
        $this->client->disableReboot();
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->purchasesUri($this->tritaniumLine), ['transactionId' => $transaction]);
        self::assertResponseStatusCodeSame(201);

        $memberId = $this->member->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $memberId), 'api');
        $this->request('POST', $this->purchasesUri($this->tritaniumLine), ['transactionId' => $transaction]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([409, 'This transaction is already linked to a purchase.'], $this->errorDetail());
        self::assertCount(1, $this->storedBuyerIdsOf($this->tritaniumLine));
    }

    public function testMemberSendingNoTransactionIsRefusedAManualPurchaseAndNothingIsStored(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->purchasesUri($this->tritaniumLine), []);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only the project owner can add a manual purchase'], $this->errorDetail());
        self::assertSame([], $this->storedBuyerIdsOf($this->tritaniumLine));
    }

    public function testOutsiderIsDeniedEvenWithTheWalletBuyOfHisOwnCharacter(): void
    {
        $transaction = $this->walletBuy($this->outsider->getMainCharacter(), self::OUTSIDER_TRITANIUM_BUY_ID);

        $this->client->loginUser($this->outsider, 'api');
        $this->request('POST', $this->purchasesUri($this->tritaniumLine), ['transactionId' => $transaction]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        self::assertSame([], $this->storedBuyerIdsOf($this->tritaniumLine));
    }

    public function testMemberCannotDeleteThePurchaseOfTheOwner(): void
    {
        $ownerPurchase = $this->purchaseOf($this->ownerMembership, self::OWNER_TRITANIUM_QUANTITY, self::OWNER_TRITANIUM_UNIT_PRICE);

        $this->client->loginUser($this->member, 'api');
        $this->request('DELETE', $this->projectUri($this->ishtarBatch).'/purchases/'.$ownerPurchase->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, self::ONLY_BUYER_OR_OWNER], $this->errorDetail());
        self::assertSame([$this->idOf($this->ownerMembership)], $this->storedBuyerIdsOf($this->tritaniumLine));
    }

    public function testOwnerDeletesThePurchaseOfTheMemberAndOnlyHisProposalGoesAway(): void
    {
        $this->purchaseOf($this->ownerMembership, self::OWNER_TRITANIUM_QUANTITY, self::OWNER_TRITANIUM_UNIT_PRICE);
        $memberPurchase = $this->purchaseOf($this->memberMembership, 40, 8.5);
        $this->derivedMaterialContribution($this->ownerMembership, self::OWNER_TRITANIUM_QUANTITY, 270.0, ContributionStatus::Approved);
        $this->derivedMaterialContribution($this->memberMembership, 40, 340.0, ContributionStatus::Pending);
        $this->tritaniumLine->setFulfilledQuantity(self::OWNER_TRITANIUM_QUANTITY);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->projectUri($this->ishtarBatch).'/purchases/'.$memberPurchase->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$this->idOf($this->ownerMembership)], $this->storedBuyerIdsOf($this->tritaniumLine));
        self::assertSame(
            [[$this->idOf($this->ownerMembership), 60, 270.0, ContributionStatus::Approved, true]],
            $this->storedDerivedMaterialContributionsOf($this->tritaniumLine),
        );
        self::assertSame(self::OWNER_TRITANIUM_QUANTITY, $this->storedFulfilledQuantityOf($this->tritaniumLine));
    }

    public function testMemberDeletesHisOwnPurchaseAndHisProposalGoesAway(): void
    {
        $memberPurchase = $this->purchaseOf($this->memberMembership, 40, 8.5);
        $this->derivedMaterialContribution($this->memberMembership, 40, 340.0, ContributionStatus::Pending);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('DELETE', $this->projectUri($this->ishtarBatch).'/purchases/'.$memberPurchase->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedBuyerIdsOf($this->tritaniumLine));
        self::assertSame([], $this->storedDerivedMaterialContributionsOf($this->tritaniumLine));
    }

    private function purchasesUri(IndustryProjectBomLine $line): string
    {
        return $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($line).'/purchases';
    }

    private function walletBuy(?Character $character, int $transactionId): string
    {
        self::assertNotNull($character);
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity(self::MEMBER_TRITANIUM_QUANTITY)
            ->setUnitPrice(self::MEMBER_TRITANIUM_UNIT_PRICE)
            ->setIsBuy(true)
            ->setLocationId(self::JITA_STATION_ID)
            ->setDate(new \DateTimeImmutable('+1 hour'));
        $this->em->persist($transaction);
        $this->em->flush();

        return (string) $transaction->getId();
    }

    private function purchaseOf(IndustryProjectParticipant $buyer, int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setBuyer($buyer)
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
        $this->tritaniumLine->addPurchase($purchase);
        $this->em->flush();

        return $purchase;
    }

    private function derivedMaterialContribution(IndustryProjectParticipant $participant, int $quantity, float $estimatedValue, ContributionStatus $status): void
    {
        $contribution = $this->addContribution($participant, $this->tritaniumLine, ContributionType::Material, $quantity, $estimatedValue, $status)
            ->setIsAutoDetected(true)
            ->setIsVerified($status === ContributionStatus::Approved);
        $this->em->persist($contribution);
    }

    private function storedBuyerIdsOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();
        $purchases = $this->em->getRepository(IndustryProjectPurchase::class)->findBy(['bomLine' => $line->getId()], ['createdAt' => 'ASC']);

        return array_map(
            static fn (IndustryProjectPurchase $purchase): ?string => $purchase->getBuyer()?->getId()->toRfc4122(),
            $purchases,
        );
    }

    private function storedDerivedMaterialContributionsOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();
        $contributions = array_filter(
            $this->em->getRepository(IndustryProjectContribution::class)->findBy(['bomItem' => $line->getId()]),
            static fn (IndustryProjectContribution $contribution): bool => $contribution->isAutoDetected() && $contribution->getType() === ContributionType::Material,
        );
        usort($contributions, static fn (IndustryProjectContribution $first, IndustryProjectContribution $second): int => $second->getQuantity() <=> $first->getQuantity());

        return array_map(
            static fn (IndustryProjectContribution $contribution): array => [
                $contribution->getMember()->getId()->toRfc4122(),
                $contribution->getQuantity(),
                $contribution->getEstimatedValue(),
                $contribution->getStatus(),
                $contribution->isVerified(),
            ],
            array_values($contributions),
        );
    }

    private function storedFulfilledQuantityOf(IndustryProjectBomLine $line): int
    {
        $this->em->clear();
        $storedLine = $this->em->find(IndustryProjectBomLine::class, $line->getId());
        self::assertNotNull($storedLine);

        return $storedLine->getFulfilledQuantity();
    }
}
