<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\PendingContributionRevaluer;
use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1009')]
final class GroupContributionWalletPurchaseApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int TRANSACTION_ID = 1_009_000_001;
    private const float TRITANIUM_JITA_PRICE = 7.0;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->givenJitaPercentilePrices([self::TRITANIUM_TYPE_ID => self::TRITANIUM_JITA_PRICE]);
    }

    public function testWalletPurchaseWithoutAnyTransactionIsPendingAndAwaitingWithAnUnknownValue(): void
    {
        $this->client->loginUser($this->member, 'api');

        $this->request('POST', $this->contributionsUri(), $this->walletPurchaseBody(2_500));

        self::assertResponseStatusCodeSame(201);
        $contribution = $this->responseJson();
        self::assertSame(['pending', 2_500, 'wallet_purchase'], [$contribution['status'], $contribution['awaitingQuantity'], $contribution['valueSource']]);
        self::assertNull($contribution['estimatedValue'] ?? null);
    }

    public function testOwnerCannotApproveAContributionStillAwaitingUnits(): void
    {
        $this->givenAWalletBuyOfTheMember(1_000, 5.0);
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), $this->walletPurchaseBody(2_500));
        self::assertResponseStatusCodeSame(201);
        $contribution = $this->responseJson();
        self::assertSame([5_000.0, 1_500], [(float) $contribution['estimatedValue'], $contribution['awaitingQuantity']]);

        $this->actAs($this->owner);
        $this->request('PATCH', $this->contributionsUri().'/'.$contribution['id'], ['status' => 'approved']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->storedFulfilledQuantityOf($this->tritaniumLine));
    }

    public function testPreviewSplitsTheQuantityBetweenTheWalletAndTheUnmatchedUnits(): void
    {
        $this->givenAWalletBuyOfTheMember(1_000, 5.0);
        $this->client->loginUser($this->member, 'api');

        $this->request('GET', $this->projectUri($this->ishtarBatch).'/material-value-previews/'.$this->idOf($this->tritaniumLine).'?quantity=2500');

        self::assertResponseStatusCodeSame(200);
        $preview = $this->responseJson();
        self::assertSame([1_000, 5_000.0, 1_500], [$preview['matchedQuantity'], (float) $preview['matchedValue'], $preview['unmatchedQuantity']]);
    }

    public function testPreviewWithoutAnyTransactionHasAnUnknownMatchedValue(): void
    {
        $this->client->loginUser($this->member, 'api');

        $this->request('GET', $this->projectUri($this->ishtarBatch).'/material-value-previews/'.$this->idOf($this->tritaniumLine).'?quantity=100');

        self::assertResponseStatusCodeSame(200);
        $preview = $this->responseJson();
        self::assertSame([0, 100], [$preview['matchedQuantity'], $preview['unmatchedQuantity']]);
        self::assertNull($preview['matchedValue'] ?? null);
    }

    public function testContributorValuesTheAwaitingUnitsAtTheJitaPriceAndOthersCannot(): void
    {
        $this->givenAWalletBuyOfTheMember(1_000, 5.0);
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), $this->walletPurchaseBody(2_500));
        $contribution = $this->responseJson();

        $this->actAs($this->owner);
        $this->request('POST', $this->contributionsUri().'/'.$contribution['id'].'/value-at-jita-price', []);
        self::assertResponseStatusCodeSame(403);

        $this->actAs($this->member);
        $this->request('POST', $this->contributionsUri().'/'.$contribution['id'].'/value-at-jita-price', []);

        self::assertResponseStatusCodeSame(200);
        $switched = $this->responseJson();
        self::assertSame([15_500.0, 0, 'jita_price', true], [(float) $switched['estimatedValue'], $switched['awaitingQuantity'], $switched['valueSource'], $switched['valuedAtJitaPrice']]);
    }

    public function testTransactionReadAfterTheDeclarationValuesTheAwaitingContribution(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), $this->walletPurchaseBody(2_500));
        $contribution = $this->responseJson();
        $this->givenAWalletBuyOfTheMember(2_500, 5.0);

        self::getContainer()->get(PendingContributionRevaluer::class)->revalueFor($this->em->find($this->member::class, $this->member->getId()));

        $this->actAs($this->member);
        $this->request('GET', $this->contributionsUri());
        $revalued = array_values(array_filter($this->responseJson(), static fn (array $listed): bool => $listed['id'] === $contribution['id']));
        self::assertSame([12_500.0, 0], [(float) $revalued[0]['estimatedValue'], $revalued[0]['awaitingQuantity']]);
    }

    private function walletPurchaseBody(int $quantity): array
    {
        return ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => $quantity, 'valueSource' => 'wallet_purchase'];
    }

    private function givenAWalletBuyOfTheMember(int $quantity, float $unitPrice): void
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($this->member->getMainCharacter())
            ->setTransactionId(self::TRANSACTION_ID)
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(60_003_760)
            ->setDate(new \DateTimeImmutable('+1 hour'));
        $this->em->persist($transaction);
        $this->em->flush();
    }

    private function givenJitaPercentilePrices(array $pricesByTypeId): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getCheapestPercentilePrices')->willReturn($pricesByTypeId);
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
    }

    private function contributionsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/contributions';
    }

    private function storedFulfilledQuantityOf(IndustryProjectBomLine $line): ?int
    {
        $this->em->clear();

        return $this->em->find(IndustryProjectBomLine::class, $line->getId())?->getFulfilledQuantity();
    }
}
