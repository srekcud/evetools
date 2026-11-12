<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectPurchaseRepository;
use App\State\Processor\Industry\CreatePurchaseProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreatePurchaseProcessor::class)]
#[Group('issue-362')]
#[Group('issue-363')]
#[AllowMockObjectsWithoutExpectations]
final class CreatePurchaseProcessorTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const string RAM_BUY_UUID = '0192f0c4-7b1e-7a3c-9d2e-362000000101';
    private const string LINKED_TRITANIUM_BUY_UUID = '0192f0c4-7b1e-7a3c-9d2e-362000000102';
    private const string ADMIN_RAM_BUY_UUID = '0192f0c4-7b1e-7a3c-9d2e-362000000109';
    private const float RAM_UNIT_PRICE = 3_040.5;

    private array $walletTransactionsByUuid = [];

    private array $storedPurchases = [];

    private array $persistedEntities = [];

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->walletTransaction(self::RAM_BUY_UUID, $this->ownerAltCharacter, 6_362_000_001, self::RAM_TYPE_ID, 50, self::RAM_UNIT_PRICE);
        $linkedTritaniumBuy = $this->walletTransaction(self::LINKED_TRITANIUM_BUY_UUID, $this->ownerMainCharacter, 6_362_000_002, self::TRITANIUM_TYPE_ID, 1_000, 4.4);
        $this->walletTransaction(self::ADMIN_RAM_BUY_UUID, $this->adminCharacter, 6_362_000_009, self::RAM_TYPE_ID, 30, 3_100.0);
        $this->storedPurchases[] = (new IndustryProjectPurchase())->setTransaction($linkedTritaniumBuy)->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(1_000);
    }

    public function testOwnerLinksHisWalletBuyOfRamToTheRamLineWithoutServingTheLine(): void
    {
        $resource = $this->create($this->owner, $this->ishtarProject, $this->ramLine, $this->transactionInput(self::RAM_BUY_UUID));

        self::assertCount(1, $this->persistedEntities);
        $purchase = $this->persistedEntities[0];
        self::assertInstanceOf(IndustryProjectPurchase::class, $purchase);
        self::assertSame(
            [self::RAM_TYPE_ID, 50, self::RAM_UNIT_PRICE, 152_025.0, 'esi_wallet', $this->walletTransactionsByUuid[self::RAM_BUY_UUID], $this->ramLine],
            [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getUnitPrice(), $purchase->getTotalPrice(), $purchase->getSource(), $purchase->getTransaction(), $purchase->getBomLine()],
        );
        self::assertSame([50, 0], [$this->ramLine->getPurchasedQuantity(), $this->ramLine->getFulfilledQuantity()]);
        self::assertSame([[$this->ramLine->getId()->toRfc4122(), 50, 0]], $this->refreshedLines);
        self::assertInstanceOf(ProjectPurchaseResource::class, $resource);
        self::assertSame(
            [$this->ramLine->getId()->toRfc4122(), self::RAM_TYPE_ID, 50, 152_025.0, 'esi_wallet'],
            [$resource->bomLineId, $resource->typeId, $resource->quantity, $resource->totalPrice, $resource->source],
        );
    }

    public function testOwnerRecordsAManualPurchaseOfTritaniumOnTheTritaniumLine(): void
    {
        $input = new CreatePurchaseInput();
        $input->typeId = self::TRITANIUM_TYPE_ID;
        $input->quantity = 2_500;
        $input->unitPrice = 4.2;

        $this->create($this->owner, $this->ishtarProject, $this->tritaniumLine, $input);

        $purchase = $this->persistedEntities[0];
        self::assertSame(
            [self::TRITANIUM_TYPE_ID, 2_500, 10_500.0, 'manual', null],
            [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getTotalPrice(), $purchase->getSource(), $purchase->getTransaction()],
        );
        self::assertSame([2_500, 0], [$this->tritaniumLine->getPurchasedQuantity(), $this->tritaniumLine->getFulfilledQuantity()]);
        self::assertSame([[$this->tritaniumLine->getId()->toRfc4122(), 2_500, 0]], $this->refreshedLines);
    }

    public function testWalletBuyAlreadyLinkedIsAConflictAndNoPurchaseIsCreated(): void
    {
        self::assertSame(
            '409 This transaction is already linked to a purchase.',
            $this->outcomeOf(fn () => $this->create($this->owner, $this->ishtarProject, $this->tritaniumLine, $this->transactionInput(self::LINKED_TRITANIUM_BUY_UUID))),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    public function testWalletBuyOfAnotherUserIsNotFoundAndNoPurchaseIsCreated(): void
    {
        self::assertSame(
            '404 Transaction not found',
            $this->outcomeOf(fn () => $this->create($this->owner, $this->ishtarProject, $this->ramLine, $this->transactionInput(self::ADMIN_RAM_BUY_UUID))),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDeniedAndNoPurchaseIsCreated(): void
    {
        self::assertSame(
            self::OWNER_ONLY,
            $this->outcomeOf(fn () => $this->create($this->admin, $this->ishtarProject, $this->ramLine, $this->transactionInput(self::ADMIN_RAM_BUY_UUID))),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    public function testLineOfAnotherProjectIsNotFoundAndNoPurchaseIsCreated(): void
    {
        self::assertSame(
            '404 BOM line not found',
            $this->outcomeOf(fn () => $this->create($this->owner, $this->ishtarProject, $this->hecateTritaniumLine, $this->transactionInput(self::RAM_BUY_UUID))),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    public function testWalletSaleIsABadRequestAndNoPurchaseIsCreated(): void
    {
        $this->walletTransactionsByUuid[self::RAM_BUY_UUID]->setIsBuy(false);

        self::assertSame(
            '400 The wallet transaction is not a purchase.',
            $this->outcomeOf(fn () => $this->create($this->owner, $this->ishtarProject, $this->ramLine, $this->transactionInput(self::RAM_BUY_UUID))),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    public function testQuantityAboveTheWalletBuyIsABadRequestAndNoPurchaseIsCreated(): void
    {
        $input = $this->transactionInput(self::RAM_BUY_UUID);
        $input->quantity = 51;

        self::assertSame(
            '400 The quantity cannot exceed the quantity of the wallet transaction.',
            $this->outcomeOf(fn () => $this->create($this->owner, $this->ishtarProject, $this->ramLine, $input)),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    public function testQuantityBelowTheWalletBuyLinksOnlyThatQuantityAtTheTransactionPrice(): void
    {
        $input = $this->transactionInput(self::RAM_BUY_UUID);
        $input->quantity = 20;

        $this->create($this->owner, $this->ishtarProject, $this->ramLine, $input);

        $purchase = $this->persistedEntities[0];
        self::assertSame([20, 60_810.0], [$purchase->getQuantity(), $purchase->getTotalPrice()]);
    }

    public function testIncompleteManualPurchaseIsABadRequest(): void
    {
        $input = new CreatePurchaseInput();
        $input->typeId = self::TRITANIUM_TYPE_ID;
        $input->quantity = 2_500;

        self::assertSame(
            '400 Manual purchase requires typeId, quantity and unitPrice',
            $this->outcomeOf(fn () => $this->create($this->owner, $this->ishtarProject, $this->tritaniumLine, $input)),
        );
        self::assertSame([], $this->persistedEntities);
        self::assertSame([], $this->refreshedLines);
    }

    private function create(User $user, IndustryProject $project, IndustryProjectBomLine $line, CreatePurchaseInput $input): mixed
    {
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?CachedWalletTransaction => $this->walletTransactionsByUuid[$id->toRfc4122()] ?? null,
        );
        $purchaseRepository = $this->createStub(IndustryProjectPurchaseRepository::class);
        $purchaseRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectPurchase => array_find(
                $this->storedPurchases,
                static fn (IndustryProjectPurchase $purchase): bool => $purchase->getTransaction() === $criteria['transaction'],
            ),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedEntities[] = $entity;
        });

        return (new CreatePurchaseProcessor(
            $this->securityOf($user),
            $this->projectRepository(),
            $this->bomLineRepository(),
            $transactionRepository,
            $purchaseRepository,
            $this->accessChecker(),
            $entityManager,
            $this->derivedContributionService(),
        ))->process($input, new Post(), $this->lineUriVariables($project, $line));
    }

    private function transactionInput(string $transactionUuid): CreatePurchaseInput
    {
        $input = new CreatePurchaseInput();
        $input->transactionId = $transactionUuid;

        return $input;
    }

    private function walletTransaction(string $uuid, Character $character, int $transactionId, int $typeId, int $quantity, float $unitPrice): CachedWalletTransaction
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(60_003_760)
            ->setDate(new \DateTimeImmutable('2026-11-02T09:30:00+00:00'));
        (new \ReflectionProperty(CachedWalletTransaction::class, 'id'))->setValue($transaction, Uuid::fromString($uuid));
        $this->walletTransactionsByUuid[$uuid] = $transaction;

        return $transaction;
    }
}
