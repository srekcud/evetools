<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\IndustryStepPurchase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectStepPurchaseApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;

    private const string NOT_A_UUID = 'abc';
    private const int PURCHASED_FUEL_BLOCKS = 120;
    private const float FUEL_BLOCK_UNIT_PRICE = 15_250.5;
    private const int PURCHASED_ISOTOPES = 2_160;
    private const float ISOTOPE_UNIT_PRICE = 480.0;

    private IndustryStepPurchase $isotopePurchase;

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
        $this->isotopePurchase = (new IndustryStepPurchase())
            ->setTypeId(self::ISOTOPE_TYPE_ID)
            ->setQuantity(self::PURCHASED_ISOTOPES)
            ->setUnitPrice(self::ISOTOPE_UNIT_PRICE)
            ->setTotalPrice(self::PURCHASED_ISOTOPES * self::ISOTOPE_UNIT_PRICE)
            ->setSource('manual');
        $this->fuelBlockStep->addPurchase($this->isotopePurchase);
        $this->em->flush();
    }

    public function testManualPurchaseOfFuelBlocksIsAnsweredWithItsTotalPrice(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($this->fuelBlockStep).'/purchases', [
            'typeId' => self::FUEL_BLOCK_TYPE_ID,
            'quantity' => self::PURCHASED_FUEL_BLOCKS,
            'unitPrice' => self::FUEL_BLOCK_UNIT_PRICE,
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->fuelBlockStep), self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', 120, 15_250.5, 1_830_060.0, 'manual'],
            [$response['stepId'], $response['typeId'], $response['typeName'], $response['quantity'], $response['unitPrice'], $response['totalPrice'], $response['source']],
        );
        self::assertArrayNotHasKey('transactionId', $response);
        self::assertSame(
            [[self::ISOTOPE_TYPE_ID, 2_160, 1_036_800.0], [self::FUEL_BLOCK_TYPE_ID, 120, 1_830_060.0]],
            $this->storedPurchasesOfTheFuelBlockStep(),
        );
    }

    public function testDeletingTheIsotopePurchaseOfTheFuelBlockStepRemovesIt(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->isotopePurchaseUri());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->storedPurchasesOfTheFuelBlockStep());
    }

    public function testDeletingTheIsotopePurchaseKeepsTheFuelBlockPurchaseOfTheSameStep(): void
    {
        $this->fuelBlockStep->addPurchase((new IndustryStepPurchase())
            ->setTypeId(self::FUEL_BLOCK_TYPE_ID)
            ->setQuantity(self::PURCHASED_FUEL_BLOCKS)
            ->setUnitPrice(self::FUEL_BLOCK_UNIT_PRICE)
            ->setTotalPrice(self::PURCHASED_FUEL_BLOCKS * self::FUEL_BLOCK_UNIT_PRICE)
            ->setSource('manual'));
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->isotopePurchaseUri());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[self::FUEL_BLOCK_TYPE_ID, 120, 1_830_060.0]], $this->storedPurchasesOfTheFuelBlockStep());
    }

    #[DataProvider('purchaseOperations')]
    public function testPurchaseOperationOnAProjectOfAnotherUserIsNotFoundAndKeepsThePurchases(string $method, string $uriAfterTheStep, ?array $body): void
    {
        $this->client->loginUser($this->intruder, 'api');
        $this->requestPurchaseOperation($method, $uriAfterTheStep, $body, $this->projectUri(), $this->idOf($this->fuelBlockStep));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
        self::assertSame([[self::ISOTOPE_TYPE_ID, 2_160, 1_036_800.0]], $this->storedPurchasesOfTheFuelBlockStep());
    }

    #[DataProvider('purchaseOperations')]
    public function testPurchaseOperationOnAProjectIdentifierThatIsNotAUuidIsNotFound(string $method, string $uriAfterTheStep, ?array $body): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->requestPurchaseOperation($method, $uriAfterTheStep, $body, '/api/industry/projects/'.self::NOT_A_UUID, $this->idOf($this->fuelBlockStep));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
        self::assertSame([[self::ISOTOPE_TYPE_ID, 2_160, 1_036_800.0]], $this->storedPurchasesOfTheFuelBlockStep());
    }

    #[DataProvider('purchaseOperations')]
    public function testPurchaseOperationOnAStepIdentifierThatIsNotAUuidIsNotFound(string $method, string $uriAfterTheStep, ?array $body): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->requestPurchaseOperation($method, $uriAfterTheStep, $body, $this->projectUri(), self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Step not found'], $this->errorDetail());
        self::assertSame([[self::ISOTOPE_TYPE_ID, 2_160, 1_036_800.0]], $this->storedPurchasesOfTheFuelBlockStep());
    }

    public function testDeletingAPurchaseWhoseIdentifierIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->stepUri($this->fuelBlockStep).'/purchases/'.self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Purchase not found'], $this->errorDetail());
        self::assertSame([[self::ISOTOPE_TYPE_ID, 2_160, 1_036_800.0]], $this->storedPurchasesOfTheFuelBlockStep());
    }

    public function testDeletingThePurchaseThroughAnotherStepOfTheProjectIsNotFoundAndKeepsIt(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->stepUri($this->carrierStep).'/purchases/'.$this->isotopePurchase->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Purchase not found'], $this->errorDetail());
        self::assertSame([[self::ISOTOPE_TYPE_ID, 2_160, 1_036_800.0]], $this->storedPurchasesOfTheFuelBlockStep());
    }

    public static function purchaseOperations(): iterable
    {
        yield 'record a purchase' => ['POST', '/purchases', ['typeId' => self::FUEL_BLOCK_TYPE_ID, 'quantity' => self::PURCHASED_FUEL_BLOCKS, 'unitPrice' => self::FUEL_BLOCK_UNIT_PRICE]];
        yield 'delete a purchase' => ['DELETE', '/purchases/{purchaseId}', null];
    }

    private function requestPurchaseOperation(string $method, string $uriAfterTheStep, ?array $body, string $projectUri, string $stepId): void
    {
        $this->request(
            $method,
            $projectUri.'/steps/'.$stepId.str_replace('{purchaseId}', (string) $this->isotopePurchase->getId()?->toRfc4122(), $uriAfterTheStep),
            $body,
        );
    }

    private function isotopePurchaseUri(): string
    {
        return $this->stepUri($this->fuelBlockStep).'/purchases/'.$this->isotopePurchase->getId()?->toRfc4122();
    }

    private function storedPurchasesOfTheFuelBlockStep(): array
    {
        $this->em->clear();
        $purchases = $this->em->getRepository(IndustryStepPurchase::class)->findBy(['step' => $this->fuelBlockStep->getId()], ['createdAt' => 'ASC']);

        return array_map(
            static fn (IndustryStepPurchase $purchase): array => [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getTotalPrice()],
            $purchases,
        );
    }
}
