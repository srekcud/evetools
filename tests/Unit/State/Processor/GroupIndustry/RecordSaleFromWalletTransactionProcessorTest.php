<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\RecordSaleFromWalletTransactionInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\RecordSaleFromWalletTransactionProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Unit\State\Provider\GroupIndustry\GroupProjectAccessProfiles;
use App\Tests\Unit\State\Provider\GroupIndustry\SabreBatchSellers;
use App\Tests\Unit\State\Provider\GroupIndustry\SabreBatchWalletTransactions;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordSaleFromWalletTransactionProcessor::class)]
#[Group('issue-241')]
final class RecordSaleFromWalletTransactionProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSellers;
    use SabreBatchWalletTransactions;

    private const string TRANSACTION_NOT_FOUND = '404 Transaction not found';
    private const string COMPLETED_PROJECT_REFUSAL = '400 Cannot record a sale on a completed project';
    private const string PURCHASE_REFUSAL = '400 This transaction is a purchase, not a sale.';
    private const string TYPE_OUTSIDE_THE_PROJECT_REFUSAL = '400 The type of this transaction is not an item of the project.';
    private const string TRANSACTION_BEFORE_THE_PROJECT_REFUSAL = '400 This transaction predates the project.';
    private const string QUANTITY_ABOVE_THE_TRANSACTION_REFUSAL = '400 The quantity exceeds the quantity of the transaction.';
    private const string ALREADY_LINKED_CONFLICT = '409 This transaction is already linked to a sale.';

    private Security&Stub $security;
    private RecordSaleFromWalletTransactionProcessor $processor;
    private ?\Throwable $flushFailure = null;
    private int $flushes = 0;

    private array $persistedSales = [];

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->createSabreBatchSellers();
        $this->createSabreBatchWalletTransactions();
        $this->security = $this->createStub(Security::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (GroupIndustrySale $sale): void {
            (new \ReflectionProperty(GroupIndustrySale::class, 'id'))->setValue($sale, Uuid::v4());
            $this->persistedSales[] = $sale;
            $this->sabreBatch->addSale($sale);
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            if ($this->flushFailure !== null) {
                throw $this->flushFailure;
            }
            ++$this->flushes;
        });
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'published';
        });

        $this->processor = new RecordSaleFromWalletTransactionProcessor(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new GroupProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->walletTransactionRepository(),
            $this->saleRepositoryLookingUpTheLinkedSales(),
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    public function testL1OwnerRecordsT1AsASaleCarryingTheValuesOfTheTransaction(): void
    {
        $this->actAsTheOwner();

        $sale = $this->recordSaleFrom($this->t1);

        self::assertSame(
            [self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, 183_750_000.0, '2026-10-24T21:05:00+00:00', self::O_MAIN_EVE_CHARACTER_ID, 'O-Main', null, 'O-Main', true],
            [
                $sale->typeId,
                $sale->typeName,
                $sale->quantity,
                $sale->unitPrice,
                $sale->totalPrice,
                $sale->soldAt,
                $sale->sellerCharacterId,
                $sale->sellerCharacterName,
                $sale->venue,
                $sale->recordedByCharacterName,
                $sale->linkedToWalletTransaction,
            ],
        );
    }

    public function testL1RecordedSaleIsPersistedOnceLinkedToT1SoldByTheMainAndRecordedByTheOwner(): void
    {
        $owner = $this->actAsTheOwner();

        $this->recordSaleFrom($this->t1);

        self::assertCount(1, $this->persistedSales);
        $recordedSale = $this->persistedSales[0];
        self::assertSame(
            [$this->sabreBatch, $this->t1, $this->oMain, $owner, 1],
            [$recordedSale->getProject(), $recordedSale->getWalletTransaction(), $recordedSale->getSeller(), $recordedSale->getRecordedBy(), $this->flushes],
        );
    }

    public function testL1PublishesTheSameSaleRecordedDataAsAManualSale(): void
    {
        $this->actAsTheOwner();

        $sale = $this->recordSaleFrom($this->t1);

        self::assertSame(
            [[
                'action' => 'sale_recorded',
                'data' => ['saleId' => $sale->id, 'typeName' => 'Sabre', 'quantity' => 3, 'totalPrice' => 183_750_000.0],
            ]],
            $this->actionsAndDataOfThePublishedUpdates(),
        );
    }

    public function testL1LeavesTheManualSaleVUnchanged(): void
    {
        $this->actAsTheOwner();

        $this->recordSaleFrom($this->t1);

        self::assertSame(
            [0, 3, 61_250_000.0, 183_750_000.0, null],
            [$this->manualSaleV->getTypeId(), $this->manualSaleV->getQuantity(), $this->manualSaleV->getUnitPrice(), $this->manualSaleV->getTotalPrice(), $this->manualSaleV->getWalletTransaction()],
        );
    }

    public function testL2RecordingT1ASecondTimeIsAConflictAndCreatesNoSecondSale(): void
    {
        $this->actAsTheOwner();
        $this->recordSaleFrom($this->t1);

        $outcome = $this->outcomeOf(fn () => $this->recordSaleFrom($this->t1));

        self::assertSame([self::ALREADY_LINKED_CONFLICT, 1, 1, 1], [$outcome, count($this->persistedSales), $this->flushes, count($this->publishedUpdates)]);
    }

    public function testTransactionLinkedToASaleOfAnotherProjectIsAConflict(): void
    {
        $this->actAsTheOwner();

        self::assertSame([self::ALREADY_LINKED_CONFLICT, 0, []], $this->refusalOf($this->t6));
    }

    public function testLinkConcurrentlyTakenByAnotherSaleIsAConflictWithoutPublishing(): void
    {
        $this->actAsTheOwner();
        $this->flushFailure = new UniqueConstraintViolationException(
            new class('duplicate key value violates unique constraint') extends AbstractException {
            },
            null,
        );

        $outcome = $this->outcomeOf(fn () => $this->recordSaleFrom($this->t1));

        self::assertSame([self::ALREADY_LINKED_CONFLICT, []], [$outcome, $this->publishedUpdates]);
    }

    public static function quantitiesWithinT7(): iterable
    {
        yield 'L3 ten of the twelve units' => [10, 10, 600_000_000.0];
        yield 'every unit of the transaction' => [12, 12, 720_000_000.0];
        yield 'a single unit' => [1, 1, 60_000_000.0];
        yield 'quantity left out' => [null, 12, 720_000_000.0];
    }

    #[DataProvider('quantitiesWithinT7')]
    public function testQuantityRecordedFromT7IsTheEnteredOneWithinTheTransactionOrTheWholeTransaction(?int $enteredQuantity, int $expectedQuantity, float $expectedTotalPrice): void
    {
        $this->actAsTheOwner();

        $sale = $this->recordSaleFrom($this->t7, $enteredQuantity);

        self::assertSame(
            [$expectedQuantity, 60_000_000.0, $expectedTotalPrice, self::O_ALT_EVE_CHARACTER_ID, 'O-Alt', '2026-10-26T11:45:00+00:00'],
            [$sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->sellerCharacterId, $sale->sellerCharacterName, $sale->soldAt],
        );
        self::assertSame($expectedTotalPrice, $this->persistedSales[0]->getTotalPrice());
    }

    public function testL4QuantityAboveTheTransactionIsRefusedWithoutRecordingNorPublishing(): void
    {
        $this->actAsTheOwner();

        self::assertSame([self::QUANTITY_ABOVE_THE_TRANSACTION_REFUSAL, 0, []], $this->refusalOf($this->t7, 13));
    }

    public static function transactionsThatCannotSellTheProduction(): iterable
    {
        yield 'L5 purchase T2' => ['t2', self::PURCHASE_REFUSAL];
        yield 'L7 Ishtar T3, not an item of the project' => ['t3', self::TYPE_OUTSIDE_THE_PROJECT_REFUSAL];
        yield 'L8 T4 sold before the creation of the project' => ['t4', self::TRANSACTION_BEFORE_THE_PROJECT_REFUSAL];
    }

    #[DataProvider('transactionsThatCannotSellTheProduction')]
    public function testTransactionThatCannotSellTheProductionOfTheProjectIsRefusedWithoutRecordingNorPublishing(string $transaction, string $expectedRefusal): void
    {
        $this->actAsTheOwner();

        self::assertSame([$expectedRefusal, 0, []], $this->refusalOf($this->{$transaction}));
    }

    public function testSaleDatedAtTheVeryCreationOfTheProjectIsRecorded(): void
    {
        $saleAtCreation = $this->walletTransaction(6_100_000_008, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_250_000.0, self::SABRE_BATCH_CREATED_AT, self::JITA_STATION_ID);
        $this->actAsTheOwner();

        $sale = $this->recordSaleFrom($saleAtCreation);

        self::assertSame([self::SABRE_BATCH_CREATED_AT, 60_250_000.0], [$sale->soldAt, $sale->totalPrice]);
    }

    public function testSaleOneSecondBeforeTheCreationOfTheProjectIsRefused(): void
    {
        $saleBeforeCreation = $this->walletTransaction(6_100_000_009, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_750_000.0, '2026-10-20T09:59:59+00:00', self::JITA_STATION_ID);
        $this->actAsTheOwner();

        self::assertSame([self::TRANSACTION_BEFORE_THE_PROJECT_REFUSAL, 0, []], $this->refusalOf($saleBeforeCreation));
    }

    public function testL6TransactionOfACharacterOfAnotherUserIsNotFound(): void
    {
        $this->actAsTheOwner();

        self::assertSame([self::TRANSACTION_NOT_FOUND, 0, []], $this->refusalOf($this->t5));
    }

    public function testL11UnknownTransactionIsNotFound(): void
    {
        $this->actAsTheOwner();

        self::assertSame(
            [self::TRANSACTION_NOT_FOUND, 0, []],
            [$this->outcomeOf(fn () => $this->recordSaleFromTransactionId(Uuid::v4()->toRfc4122(), null)), count($this->persistedSales), $this->publishedUpdates],
        );
    }

    public function testTransactionOfAnotherUserIsNotFoundEvenWhenItIsAPurchaseOrAlreadyLinked(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain));

        self::assertSame(
            [self::TRANSACTION_NOT_FOUND, self::TRANSACTION_NOT_FOUND, self::TRANSACTION_NOT_FOUND],
            [
                $this->outcomeOf(fn () => $this->recordSaleFrom($this->t2)),
                $this->outcomeOf(fn () => $this->recordSaleFrom($this->t6)),
                $this->outcomeOf(fn () => $this->recordSaleFrom($this->t7, 13)),
            ],
        );
    }

    public function testAdminRecordsT5SoldByHisOwnCharacter(): void
    {
        $admin = $this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain);
        $this->security->method('getUser')->willReturn($admin);

        $sale = $this->recordSaleFrom($this->t5);

        self::assertSame(
            [2, 61_000_000.0, 122_000_000.0, self::A_MAIN_EVE_CHARACTER_ID, 'A-Main', 'A-Main', true],
            [$sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->sellerCharacterId, $sale->sellerCharacterName, $sale->recordedByCharacterName, $sale->linkedToWalletTransaction],
        );
        self::assertSame([$this->t5, $admin], [$this->persistedSales[0]->getWalletTransaction(), $this->persistedSales[0]->getRecordedBy()]);
    }

    public function testL9RecordingT7OnACompletedProjectIsRefused(): void
    {
        $this->actAsTheOwner();
        $this->sabreBatch->setStatus(GroupProjectStatus::Completed);

        self::assertSame([self::COMPLETED_PROJECT_REFUSAL, 0, []], $this->refusalOf($this->t7));
    }

    public function testCompletedProjectIsRefusedBeforeTheTransactionIsLookedAt(): void
    {
        $this->actAsTheOwner();
        $this->sabreBatch->setStatus(GroupProjectStatus::Completed);

        self::assertSame(
            [self::COMPLETED_PROJECT_REFUSAL, self::COMPLETED_PROJECT_REFUSAL],
            [$this->outcomeOf(fn () => $this->recordSaleFrom($this->t5)), $this->outcomeOf(fn () => $this->recordSaleFrom($this->t2))],
        );
    }

    public function testQuantityAboveTheTransactionIsRefusedBeforeTheConflict(): void
    {
        $this->actAsTheOwner();

        self::assertSame([self::QUANTITY_ABOVE_THE_TRANSACTION_REFUSAL, 0, []], $this->refusalOf($this->t6, 3));
    }

    public static function profilesRefusedTheRecording(): iterable
    {
        yield 'L10 accepted member' => [self::ACCEPTED_MEMBER];
        yield 'pending member' => [self::PENDING_MEMBER];
        yield 'owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER];
        yield 'removed member' => [self::REMOVED_MEMBER];
        yield 'stranger' => [self::STRANGER];
    }

    #[DataProvider('profilesRefusedTheRecording')]
    public function testOnlyTheOwnerAndAcceptedAdminsRecordASaleFromAWalletTransaction(string $profile): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile($profile), $this->mMain));

        self::assertSame([self::ADMIN_OR_OWNER_ONLY, 0, []], $this->refusalOf($this->t1));
    }

    public function testRecordingOnAnUnknownProjectIsNotFound(): void
    {
        $this->actAsTheOwner();
        $input = $this->inputFor($this->t1->getId()?->toRfc4122(), null);

        $outcome = $this->outcomeOf(fn () => $this->processor->process($input, new Post(), ['projectId' => Uuid::v4()->toRfc4122()]));

        self::assertSame([self::PROJECT_NOT_FOUND, 0], [$outcome, count($this->persistedSales)]);
    }

    public function testRecordingWithoutUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame([self::UNAUTHORIZED, 0, []], $this->refusalOf($this->t1));
    }

    private function actAsTheOwner(): User
    {
        $owner = $this->withCharacters($this->userWithProfile(self::OWNER), $this->oMain, $this->oAlt);
        $this->security->method('getUser')->willReturn($owner);

        return $owner;
    }

    private function recordSaleFrom(CachedWalletTransaction $transaction, ?int $quantity = null): GroupIndustrySaleResource
    {
        return $this->recordSaleFromTransactionId($transaction->getId()?->toRfc4122(), $quantity);
    }

    private function recordSaleFromTransactionId(?string $transactionId, ?int $quantity): GroupIndustrySaleResource
    {
        return $this->processor->process($this->inputFor($transactionId, $quantity), new Post(), [
            'projectId' => $this->sabreBatch->getId()?->toRfc4122(),
        ]);
    }

    private function inputFor(?string $transactionId, ?int $quantity): RecordSaleFromWalletTransactionInput
    {
        $input = new RecordSaleFromWalletTransactionInput();
        $input->transactionId = (string) $transactionId;
        $input->quantity = $quantity;

        return $input;
    }

    private function refusalOf(CachedWalletTransaction $transaction, ?int $quantity = null): array
    {
        $outcome = $this->outcomeOf(fn () => $this->recordSaleFrom($transaction, $quantity));

        return [$outcome, count($this->persistedSales), $this->publishedUpdates];
    }

    private function saleRepositoryLookingUpTheLinkedSales(): GroupIndustrySaleRepository&Stub
    {
        $saleRepository = $this->createStub(GroupIndustrySaleRepository::class);
        $saleRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?GroupIndustrySale {
                if (array_keys($criteria) !== ['walletTransaction']) {
                    self::fail('Unexpected criteria ' . implode(', ', array_keys($criteria)));
                }

                return $this->saleLinkedTo($criteria['walletTransaction']);
            },
        );

        return $saleRepository;
    }

    private function actionsAndDataOfThePublishedUpdates(): array
    {
        return array_map(static function (Update $update): array {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

            return ['action' => $payload['action'], 'data' => $payload['data']];
        }, $this->publishedUpdates);
    }
}
