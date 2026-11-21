<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\Project\RecordedSalesBatchResource;
use App\ApiResource\Input\Industry\Project\RecordSaleFromWalletTransactionInput;
use App\ApiResource\Input\Industry\Project\RecordSalesFromWalletTransactionsInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Event\GroupProjectEventPublished;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\Project\RecordSaleFromWalletTransactionProcessor;
use App\State\Processor\Industry\Project\RecordSalesFromWalletTransactionsProcessor;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\Tests\Unit\State\Provider\Industry\Project\GroupProjectAccessProfiles;
use App\Tests\Unit\State\Provider\Industry\Project\SabreBatchSellers;
use App\Tests\Unit\State\Provider\Industry\Project\SabreBatchWalletTransactions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordSalesFromWalletTransactionsProcessor::class)]
#[CoversClass(RecordSaleFromWalletTransactionProcessor::class)]
#[Group('issue-1152')]
final class RecordSalesFromWalletTransactionsProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSellers;
    use SabreBatchWalletTransactions;

    private const string TRANSACTION_NOT_FOUND = 'Transaction not found';
    private const string PURCHASE_REFUSAL = 'This transaction is a purchase, not a sale.';
    private const string ALREADY_LINKED = 'This transaction is already linked to a sale.';
    private const string COMPLETED_PROJECT_REFUSAL = '400 Cannot record a sale on a completed project';

    private Security&Stub $security;
    private RecordSalesFromWalletTransactionsProcessor $processor;
    private RecordSaleFromWalletTransactionProcessor $singleProcessor;
    private array $persistedSales = [];
    private array $publishedUpdates = [];
    private array $recordedActivities = [];

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->createSabreBatchSellers();
        $this->createSabreBatchWalletTransactions();
        $this->security = $this->createStub(Security::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (IndustryProjectSale $sale): void {
            (new \ReflectionProperty(IndustryProjectSale::class, 'id'))->setValue($sale, Uuid::v4());
            $this->persistedSales[] = $sale;
            $this->sabreBatch->addSale($sale);
        });
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'published';
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(GroupProjectEventPublished::class, function (GroupProjectEventPublished $event): void {
            $this->recordedActivities[] = [$event->action, $event->data];
        });
        $mercure = new MercurePublisherService($hub, new NullLogger(), $dispatcher);
        $accessChecker = new ProjectAccessChecker($this->sabreBatchMemberRepository());

        $this->singleProcessor = new RecordSaleFromWalletTransactionProcessor(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $accessChecker,
            $this->walletTransactionRepository(),
            $this->saleRepositoryLookingUpTheLinkedSales(),
            $entityManager,
            $mercure,
        );
        $this->processor = new RecordSalesFromWalletTransactionsProcessor(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $this->singleProcessor,
            $mercure,
        );
    }

    public function testRecordingT1AndT7TogetherPublishesOneSaleRecordedUpdateSummarisingBothSales(): void
    {
        $this->actAsTheOwner();

        $batch = $this->recordBatch([[$this->t1, null], [$this->t7, null]]);

        self::assertSame(
            [2, [], 1, [['action' => 'sale_recorded', 'data' => ['count' => 2, 'quantity' => 15, 'totalPrice' => 903_750_000.0]]]],
            [count($batch->recorded), $batch->failures, count($this->publishedUpdates), $this->actionsAndDataOfThePublishedUpdates()],
        );
    }

    public function testEachRecordedSaleKeepsItsOwnEntryInTheProjectJournal(): void
    {
        $this->actAsTheOwner();

        $batch = $this->recordBatch([[$this->t1, null], [$this->t7, 10]]);

        self::assertSame(
            [
                ['sale_recorded', ['saleId' => $batch->recorded[0]->id, 'typeName' => 'Sabre', 'quantity' => 3, 'totalPrice' => 183_750_000.0]],
                ['sale_recorded', ['saleId' => $batch->recorded[1]->id, 'typeName' => 'Sabre', 'quantity' => 10, 'totalPrice' => 600_000_000.0]],
            ],
            $this->recordedActivities,
        );
    }

    public function testRecordedSalesCarryTheValuesOfTheirTransactionsInTheOrderReceived(): void
    {
        $owner = $this->actAsTheOwner();

        $batch = $this->recordBatch([[$this->t7, 5], [$this->t1, null]]);

        self::assertSame(
            [[5, 60_000_000.0, 'O-Alt'], [3, 61_250_000.0, 'O-Main']],
            array_map(static fn ($sale): array => [$sale->quantity, $sale->unitPrice, $sale->sellerCharacterName], $batch->recorded),
        );
        self::assertSame(
            [$this->t7, $this->t1, $owner, $owner],
            [$this->persistedSales[0]->getWalletTransaction(), $this->persistedSales[1]->getWalletTransaction(), $this->persistedSales[0]->getRecordedBy(), $this->persistedSales[1]->getRecordedBy()],
        );
    }

    public function testALineRefusedByTheRulesOfASingleRecordingIsListedAndTheOthersAreStillRecordedWithOneUpdate(): void
    {
        $this->actAsTheOwner();
        $unknownId = Uuid::v4()->toRfc4122();

        $batch = $this->processor->process(
            $this->inputOf([[$this->t1->getId()?->toRfc4122(), null], [$unknownId, null], [$this->t2->getId()?->toRfc4122(), null], [$this->t7->getId()?->toRfc4122(), null]]),
            new Post(),
            $this->uriVariables(),
        );

        self::assertSame(
            [
                2,
                [
                    ['transactionId' => $unknownId, 'message' => self::TRANSACTION_NOT_FOUND],
                    ['transactionId' => $this->t2->getId()?->toRfc4122(), 'message' => self::PURCHASE_REFUSAL],
                ],
                1,
                2,
            ],
            [count($batch->recorded), $batch->failures, count($this->publishedUpdates), $this->actionsAndDataOfThePublishedUpdates()[0]['data']['count']],
        );
    }

    public function testNoUpdateAndNoJournalEntryWhenEveryLineIsRefused(): void
    {
        $this->actAsTheOwner();

        $batch = $this->recordBatch([[$this->t2, null]]);

        self::assertSame(
            [[], 1, [], []],
            [$batch->recorded, count($batch->failures), $this->publishedUpdates, $this->recordedActivities],
        );
    }

    public function testATransactionRepeatedInTheRequestIsRecordedOnceAndItsSecondOccurrenceIsAlreadyLinked(): void
    {
        $this->actAsTheOwner();

        $batch = $this->recordBatch([[$this->t1, null], [$this->t1, null]]);

        self::assertSame(
            [1, [['transactionId' => $this->t1->getId()?->toRfc4122(), 'message' => self::ALREADY_LINKED]], 1, 1],
            [count($batch->recorded), $batch->failures, count($this->persistedSales), count($this->publishedUpdates)],
        );
    }

    public function testAnotherUsersWalletTransactionIsNotFoundWithoutStoppingTheBatch(): void
    {
        $this->actAsTheOwner();

        $batch = $this->recordBatch([[$this->t5, null], [$this->t1, null]]);

        self::assertSame(
            [1, [['transactionId' => $this->t5->getId()?->toRfc4122(), 'message' => self::TRANSACTION_NOT_FOUND]]],
            [count($batch->recorded), $batch->failures],
        );
    }

    public function testAMemberWhoIsNeitherOwnerNorAdminRecordsNothingAndPublishesNothing(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_MEMBER), $this->mMain));

        $outcome = $this->outcomeOf(fn () => $this->recordBatch([[$this->t1, null], [$this->t7, null]]));

        self::assertSame([self::ADMIN_OR_OWNER_ONLY, 0, []], [$outcome, count($this->persistedSales), $this->publishedUpdates]);
    }

    public function testACompletedProjectRefusesTheWholeRequest(): void
    {
        $this->actAsTheOwner();
        $this->sabreBatch->setStatus(ProjectStatus::Completed);

        $outcome = $this->outcomeOf(fn () => $this->recordBatch([[$this->t1, null], [$this->t7, null]]));

        self::assertSame([self::COMPLETED_PROJECT_REFUSAL, 0, []], [$outcome, count($this->persistedSales), $this->publishedUpdates]);
    }

    public function testWithoutUserTheRequestIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $outcome = $this->outcomeOf(fn () => $this->recordBatch([[$this->t1, null]]));

        self::assertSame(self::UNAUTHORIZED, $outcome);
    }

    public function testAnUnknownProjectIsNotFound(): void
    {
        $this->actAsTheOwner();

        $outcome = $this->outcomeOf(fn () => $this->processor->process(
            $this->inputOf([[$this->t1->getId()?->toRfc4122(), null]]),
            new Post(),
            ['projectId' => Uuid::v4()->toRfc4122()],
        ));

        self::assertSame([self::PROJECT_NOT_FOUND, 0], [$outcome, count($this->persistedSales)]);
    }

    public function testASingleRecordingStillPublishesItsOwnSaleRecordedUpdate(): void
    {
        $this->actAsTheOwner();
        $single = new RecordSaleFromWalletTransactionInput();
        $single->transactionId = (string) $this->t1->getId()?->toRfc4122();

        $sale = $this->singleProcessor->process($single, new Post(), $this->uriVariables());

        self::assertSame(
            [[['action' => 'sale_recorded', 'data' => ['saleId' => $sale->id, 'typeName' => 'Sabre', 'quantity' => 3, 'totalPrice' => 183_750_000.0]]], 1],
            [$this->actionsAndDataOfThePublishedUpdates(), count($this->recordedActivities)],
        );
    }

    private function actAsTheOwner(): User
    {
        $owner = $this->withCharacters($this->userWithProfile(self::OWNER), $this->oMain, $this->oAlt);
        $this->security->method('getUser')->willReturn($owner);

        return $owner;
    }

    private function recordBatch(array $lines): RecordedSalesBatchResource
    {
        return $this->processor->process(
            $this->inputOf(array_map(static fn (array $line): array => [$line[0]->getId()?->toRfc4122(), $line[1]], $lines)),
            new Post(),
            $this->uriVariables(),
        );
    }

    private function inputOf(array $lines): RecordSalesFromWalletTransactionsInput
    {
        $input = new RecordSalesFromWalletTransactionsInput();
        $input->transactions = array_map(static function (array $line): RecordSaleFromWalletTransactionInput {
            $transaction = new RecordSaleFromWalletTransactionInput();
            $transaction->transactionId = (string) $line[0];
            $transaction->quantity = $line[1];

            return $transaction;
        }, $lines);

        return $input;
    }

    private function uriVariables(): array
    {
        return ['projectId' => $this->sabreBatch->getId()?->toRfc4122()];
    }

    private function saleRepositoryLookingUpTheLinkedSales(): IndustryProjectSaleRepository&Stub
    {
        $saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $saleRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectSale => $this->saleLinkedTo($criteria['walletTransaction']),
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
