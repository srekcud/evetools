<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StepPurchaseResource;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\State\Processor\Industry\CreatePurchaseProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreatePurchaseProcessor::class)]
final class CreatePurchaseProcessorTest extends TestCase
{
    private const string CREATED = 'created';
    private const string TRANSACTION_NOT_FOUND = '404 Transaction not found';
    private const string ALREADY_LINKED_CONFLICT = '409 This transaction is already linked to a purchase.';

    private const string J1_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-205000000001';
    private const string S1_STEP_ID = '0192f0c4-7b1e-7a3c-9d2e-205000000011';
    private const string T1_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000101';
    private const string T2_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000102';
    private const string T3_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000103';
    private const string T4_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000104';
    private const string T9_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000109';
    private const string UNKNOWN_TRANSACTION_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000199';

    private IndustryProjectStep $s1;
    private IndustryProjectStep $s1b;
    private IndustryProjectStep $s2;
    private IndustryProjectStep $s9;
    private CachedWalletTransaction $t1;
    private CachedWalletTransaction $t2;
    private CachedWalletTransaction $t3;
    private CachedWalletTransaction $t4;
    private CachedWalletTransaction $t9;

    private array $walletTransactionsByUuid = [];

    private array $existingPurchases = [];

    private array $persistedPurchases = [];

    private int $flushes = 0;

    private int $walletTransactionLookups = 0;

    private int $linkedPurchaseLookups = 0;

    private CreatePurchaseProcessor $processor;

    protected function setUp(): void
    {
        $u1 = new User();
        $p1 = $this->character('Pilot One', 2_120_000_001);
        $p2 = $this->character('Pilot Two', 2_120_000_002);
        $u1->addCharacter($p1);
        $u1->addCharacter($p2);
        $u2 = new User();
        $p9 = $this->character('Other Pilot', 2_120_000_009);
        $u2->addCharacter($p9);

        $j1 = (new IndustryProject())->setUser($u1)->setProductTypeId(9000)->setRuns(4);
        $this->s1 = $this->step(9200, 9000, 4, 0);
        $this->s1b = $this->step(9201, 9101, 30, 1);
        $j1->addStep($this->s1);
        $j1->addStep($this->s1b);
        $j2 = (new IndustryProject())->setUser($u1)->setProductTypeId(9010)->setRuns(2);
        $this->s2 = $this->step(9202, 9010, 2, 0);
        $j2->addStep($this->s2);
        $j9 = (new IndustryProject())->setUser($u2)->setProductTypeId(9090)->setRuns(1);
        $this->s9 = $this->step(9209, 9090, 1, 0);
        $j9->addStep($this->s9);

        $this->t1 = $this->walletTransaction(self::T1_UUID, $p1, 6_300_000_001, 9101, 120, 5.25);
        $this->t2 = $this->walletTransaction(self::T2_UUID, $p2, 6_300_000_002, 9102, 60, 14.5);
        $this->t3 = $this->walletTransaction(self::T3_UUID, $p1, 6_300_000_003, 9104, 25, 230.0);
        $this->t4 = $this->walletTransaction(self::T4_UUID, $p2, 6_300_000_004, 9106, 15, 1_250.0);
        $this->t9 = $this->walletTransaction(self::T9_UUID, $p9, 6_300_000_009, 9105, 45, 33.0);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($u1);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($j1);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($this->s1);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('purchaseToResource')->willReturn(new StepPurchaseResource());

        $this->processor = new CreatePurchaseProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->walletTransactionRepository(),
            $this->purchaseRepositoryLookingUpTheLinkedPurchases(),
            $mapper,
            $this->entityManagerRecordingThePersistedPurchases(),
        );
    }

    #[Group('issue-205')]
    #[Group('issue-285')]
    public function testE1LinkingAnOwnUnlinkedWalletTransactionCreatesAPurchaseCopyingItsTypeQuantityAndPrices(): void
    {
        $outcome = $this->outcomeOf($this->linkInput(self::T1_UUID, null));

        self::assertSame([self::CREATED, 1, 1], [$outcome, count($this->persistedPurchases), $this->flushes]);
        $purchase = $this->persistedPurchases[0];
        self::assertSame(
            [9101, 120, 5.25, 630.0, 'esi_wallet', $this->t1, $this->s1, [$purchase]],
            [
                $purchase->getTypeId(),
                $purchase->getQuantity(),
                $purchase->getUnitPrice(),
                $purchase->getTotalPrice(),
                $purchase->getSource(),
                $purchase->getTransaction(),
                $purchase->getStep(),
                $this->s1->getPurchases()->toArray(),
            ],
        );
    }

    #[Group('issue-205')]
    #[Group('issue-285')]
    public function testE2LinkingAnOwnWalletTransactionWithAPartialQuantityPricesOnlyThatQuantity(): void
    {
        $outcome = $this->outcomeOf($this->linkInput(self::T1_UUID, 80));

        self::assertSame([self::CREATED, 1, 1], [$outcome, count($this->persistedPurchases), $this->flushes]);
        $purchase = $this->persistedPurchases[0];
        self::assertSame(
            [9101, 80, 5.25, 420.0, $this->t1],
            [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getUnitPrice(), $purchase->getTotalPrice(), $purchase->getTransaction()],
        );
    }

    #[Group('issue-285')]
    public function testE3WalletTransactionOfAnotherUserIsNotFoundWithoutLookingUpTheLinkedPurchasesAndNoPurchaseIsCreated(): void
    {
        $outcome = $this->outcomeOf($this->linkInput(self::T9_UUID, null));

        self::assertSame(
            [self::TRANSACTION_NOT_FOUND, 0, 0, 0, 0],
            [$outcome, count($this->persistedPurchases), $this->flushes, $this->linkedPurchaseLookups, $this->s1->getPurchases()->count()],
        );
    }

    #[Group('issue-205')]
    #[Group('issue-285')]
    public function testE4UnknownWalletTransactionIsNotFoundAndNoPurchaseIsCreated(): void
    {
        $outcome = $this->outcomeOf($this->linkInput(self::UNKNOWN_TRANSACTION_UUID, null));

        self::assertSame([self::TRANSACTION_NOT_FOUND, 0, 0], [$outcome, count($this->persistedPurchases), $this->flushes]);
    }

    #[Group('issue-285')]
    public function testE5TransactionIdThatIsNotAUuidIsNotFoundWithoutReadingTheWalletTransactions(): void
    {
        $outcome = $this->outcomeOf($this->linkInput('not-a-uuid', null));

        self::assertSame(
            [self::TRANSACTION_NOT_FOUND, 0, 0, 0],
            [$outcome, $this->walletTransactionLookups, count($this->persistedPurchases), $this->flushes],
        );
    }

    #[Group('issue-205')]
    public function testE6OwnWalletTransactionAlreadyLinkedToAPurchaseOfAnotherProjectIsAConflictAndNoPurchaseIsCreated(): void
    {
        $this->linkInAnExistingPurchase($this->s2, $this->t2, 60);

        $outcome = $this->outcomeOf($this->linkInput(self::T2_UUID, null));

        self::assertSame(
            [self::ALREADY_LINKED_CONFLICT, 0, 0, 0],
            [$outcome, count($this->persistedPurchases), $this->flushes, $this->s1->getPurchases()->count()],
        );
    }

    #[Group('issue-205')]
    public function testE7OwnWalletTransactionAlreadyLinkedToAnotherStepOfTheSameProjectIsAConflict(): void
    {
        $this->linkInAnExistingPurchase($this->s1b, $this->t3, 25);

        $outcome = $this->outcomeOf($this->linkInput(self::T3_UUID, null));

        self::assertSame([self::ALREADY_LINKED_CONFLICT, 0, 0], [$outcome, count($this->persistedPurchases), $this->flushes]);
    }

    #[Group('issue-205')]
    public function testE8PartialQuantityOfAnAlreadyLinkedWalletTransactionIsAConflict(): void
    {
        $this->linkInAnExistingPurchase($this->s1b, $this->t3, 15);

        $outcome = $this->outcomeOf($this->linkInput(self::T3_UUID, 10));

        self::assertSame([self::ALREADY_LINKED_CONFLICT, 0, 0], [$outcome, count($this->persistedPurchases), $this->flushes]);
    }

    #[Group('issue-205')]
    public function testWalletTransactionAlreadyLinkedToAPurchaseOfTheSameStepIsAConflict(): void
    {
        $this->linkInAnExistingPurchase($this->s1, $this->t4, 15);

        $outcome = $this->outcomeOf($this->linkInput(self::T4_UUID, null));

        self::assertSame(
            [self::ALREADY_LINKED_CONFLICT, 0, 0, 1],
            [$outcome, count($this->persistedPurchases), $this->flushes, $this->s1->getPurchases()->count()],
        );
    }

    #[Group('issue-205')]
    #[Group('issue-285')]
    public function testE9WalletTransactionOfAnotherUserAlreadyLinkedInHisProjectIsNotFoundRatherThanAConflict(): void
    {
        $this->linkInAnExistingPurchase($this->s9, $this->t9, 45);

        $outcome = $this->outcomeOf($this->linkInput(self::T9_UUID, null));

        self::assertSame(
            [self::TRANSACTION_NOT_FOUND, 0, 0, 0],
            [$outcome, count($this->persistedPurchases), $this->flushes, $this->linkedPurchaseLookups],
        );
    }

    #[Group('issue-205')]
    #[Group('issue-285')]
    public function testE10ManualPurchaseIsCreatedWithoutReadingWalletTransactionsNorLinkedPurchases(): void
    {
        $input = new CreatePurchaseInput();
        $input->typeId = 9103;
        $input->quantity = 7;
        $input->unitPrice = 1_000.0;

        $outcome = $this->outcomeOf($input);

        self::assertSame(
            [self::CREATED, 1, 1, 0, 0],
            [$outcome, count($this->persistedPurchases), $this->flushes, $this->walletTransactionLookups, $this->linkedPurchaseLookups],
        );
        $purchase = $this->persistedPurchases[0];
        self::assertSame(
            [9103, 7, 1_000.0, 7_000.0, 'manual', null, $this->s1],
            [
                $purchase->getTypeId(),
                $purchase->getQuantity(),
                $purchase->getUnitPrice(),
                $purchase->getTotalPrice(),
                $purchase->getSource(),
                $purchase->getTransaction(),
                $purchase->getStep(),
            ],
        );
    }

    private function outcomeOf(CreatePurchaseInput $input): string
    {
        try {
            $this->processor->process($input, new Post(), ['id' => self::J1_PROJECT_ID, 'stepId' => self::S1_STEP_ID]);
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() . ' ' . $exception->getMessage();
        } catch (\Throwable $throwable) {
            return $throwable::class . ': ' . $throwable->getMessage();
        }

        return self::CREATED;
    }

    private function linkInput(string $transactionId, ?int $quantity): CreatePurchaseInput
    {
        $input = new CreatePurchaseInput();
        $input->transactionId = $transactionId;
        $input->quantity = $quantity;

        return $input;
    }

    private function linkInAnExistingPurchase(IndustryProjectStep $step, CachedWalletTransaction $transaction, int $quantity): IndustryStepPurchase
    {
        $purchase = (new IndustryStepPurchase())
            ->setTransaction($transaction)
            ->setTypeId($transaction->getTypeId())
            ->setQuantity($quantity)
            ->setUnitPrice($transaction->getUnitPrice())
            ->setTotalPrice($transaction->getUnitPrice() * $quantity)
            ->setSource('esi_wallet');
        $step->addPurchase($purchase);
        $this->existingPurchases[] = $purchase;

        return $purchase;
    }

    private function walletTransactionRepository(): CachedWalletTransactionRepository
    {
        $repository = $this->createStub(CachedWalletTransactionRepository::class);
        $repository->method('find')->willReturnCallback(function (mixed $id): ?CachedWalletTransaction {
            ++$this->walletTransactionLookups;

            return $id instanceof Uuid ? ($this->walletTransactionsByUuid[$id->toRfc4122()] ?? null) : null;
        });

        return $repository;
    }

    private function purchaseRepositoryLookingUpTheLinkedPurchases(): IndustryStepPurchaseRepository
    {
        $repository = $this->createStub(IndustryStepPurchaseRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?IndustryStepPurchase {
            ++$this->linkedPurchaseLookups;
            if (array_keys($criteria) !== ['transaction']) {
                self::fail('Unexpected criteria ' . implode(', ', array_keys($criteria)));
            }
            foreach ($this->existingPurchases as $purchase) {
                if ($purchase->getTransaction() === $criteria['transaction']) {
                    return $purchase;
                }
            }

            return null;
        });

        return $repository;
    }

    private function entityManagerRecordingThePersistedPurchases(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $purchase): void {
            $this->persistedPurchases[] = $purchase;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $entityManager;
    }

    private function character(string $name, int $eveCharacterId): Character
    {
        return (new Character())->setName($name)->setEveCharacterId($eveCharacterId);
    }

    private function step(int $blueprintTypeId, int $productTypeId, int $runs, int $depth): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($runs)
            ->setRuns($runs)
            ->setDepth($depth)
            ->setActivityType('manufacturing')
            ->setSortOrder($depth);
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
            ->setClientId(1_000_125)
            ->setDate(new \DateTimeImmutable('2026-10-20T09:30:00+00:00'));
        (new \ReflectionProperty(CachedWalletTransaction::class, 'id'))->setValue($transaction, Uuid::fromString($uuid));
        $this->walletTransactionsByUuid[$uuid] = $transaction;

        return $transaction;
    }
}
