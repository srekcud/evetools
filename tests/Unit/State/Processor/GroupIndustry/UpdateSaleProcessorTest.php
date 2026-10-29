<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\UpdateSaleInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\GroupIndustrySale;
use App\Enum\GroupProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\UpdateSaleProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleItemProvider;
use App\Tests\Unit\State\Provider\GroupIndustry\GroupProjectAccessProfiles;
use App\Tests\Unit\State\Provider\GroupIndustry\SabreBatchSales;
use App\Tests\Unit\State\Provider\GroupIndustry\SabreBatchSellers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateSaleProcessor::class)]
#[Group('issue-164')]
#[AllowMockObjectsWithoutExpectations]
final class UpdateSaleProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSales;
    use SabreBatchSellers;

    private const int SABRE_TYPE_ID_OF_T1 = 22456;
    private const string LINKED_SALE_REFUSAL = '400 This sale is linked to a wallet transaction.';

    private Security&Stub $security;
    private GroupSaleItemProvider $provider;
    private UpdateSaleProcessor $processor;
    private int $flushes = 0;

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);
        $this->recordSabreSale();
        $this->createHecateBatchOfTheSabreBatchOwner();
        $this->createSabreBatchSellers();
        $this->security = $this->createStub(Security::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'published';
        });

        $projectRepository = $this->projectRepositoryOfTheSabreAndHecateBatches();
        $saleRepository = $this->saleRepositoryOfTheSabreSale();
        $accessChecker = new GroupProjectAccessChecker($this->sabreBatchMemberRepository());

        $this->provider = new GroupSaleItemProvider($this->security, $projectRepository, $saleRepository, $accessChecker);
        $this->processor = new UpdateSaleProcessor(
            $this->security,
            $projectRepository,
            $saleRepository,
            $accessChecker,
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    public function testAdminCorrectsTheUnitPriceAndTheTotalFollows(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['unitPrice' => 950000.0]);

        self::assertSame(
            [950000.0, 2, 1900000.0, self::SABRE_SALE_RECORDER_NAME, self::SABRE_SALE_CREATED_AT],
            [$sale->unitPrice, $sale->quantity, $sale->totalPrice, $sale->recordedByCharacterName, $sale->createdAt],
        );
        self::assertSame([950000.0, 2, 1900000.0], $this->persistedSabreSaleAmounts());
        self::assertSame(1, $this->flushes);
    }

    public function testCorrectionPublishesTheSaleUpdatedEventWithTheNewTotal(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $this->correctSabreSale(['unitPrice' => 950000.0]);

        self::assertSame(
            [[
                'action' => 'sale_updated',
                'data' => ['saleId' => $this->sabreSale->getId()->toRfc4122(), 'typeName' => 'Sabre', 'quantity' => 2, 'totalPrice' => 1900000.0],
            ]],
            $this->actionsAndDataOf($this->publishedUpdates),
        );
    }

    public function testOwnerCorrectsTheQuantityAndTheSaleDate(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $sale = $this->correctSabreSale(['quantity' => 3, 'soldAt' => '2026-10-24T09:00:00+00:00']);

        self::assertSame(
            [3, 900000.0, 2700000.0, '2026-10-24T09:00:00+00:00'],
            [$sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->soldAt],
        );
        self::assertSame('2026-10-24T09:00:00+00:00', $this->sabreSale->getSoldAt()->format(\DateTimeInterface::ATOM));
    }

    public function testCorrectingQuantityAndUnitPriceTogetherMultipliesTheNewValues(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $sale = $this->correctSabreSale(['quantity' => 4, 'unitPrice' => 875000.0]);

        self::assertSame([4, 875000.0, 3500000.0], [$sale->quantity, $sale->unitPrice, $sale->totalPrice]);
    }

    public function testCorrectionOfTheItemNameAndTypeKeepsTheAmounts(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $sale = $this->correctSabreSale(['typeId' => 22456, 'typeName' => 'Sabre Navy Issue']);

        self::assertSame(
            [22456, 'Sabre Navy Issue', 2, 900000.0, 1800000.0],
            [$sale->typeId, $sale->typeName, $sale->quantity, $sale->unitPrice, $sale->totalPrice],
        );
    }

    public function testCorrectionWithANullVenueKeepsTheVenue(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['venue' => null]);

        self::assertSame([self::SABRE_SALE_VENUE, self::SABRE_SALE_VENUE], [$sale->venue, $this->sabreSale->getVenue()]);
    }

    public function testCorrectionWithoutAnyFieldKeepsTheWholeSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale([]);

        self::assertSame(
            [0, 'Sabre', 2, 900000.0, 1800000.0, self::SABRE_SALE_VENUE, self::SABRE_SALE_SOLD_AT],
            [$sale->typeId, $sale->typeName, $sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->venue, $sale->soldAt],
        );
    }

    public function testSaleDateWrittenZIsStoredAsUtc(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['soldAt' => '2026-10-24T09:00:00Z']);

        self::assertSame('2026-10-24T09:00:00+00:00', $sale->soldAt);
    }

    public function testCorrectionOfASaleWhoseRecorderDeletedTheAccountKeepsNoRecorderName(): void
    {
        (new \ReflectionProperty(GroupIndustrySale::class, 'recordedBy'))->setValue($this->sabreSale, null);
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['unitPrice' => 950000.0]);

        self::assertSame([1900000.0, null], [$sale->totalPrice, $sale->recordedByCharacterName]);
    }

    public static function correctionAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'C6 A3 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A9 stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
    }

    #[DataProvider('correctionAccessMatrix')]
    public function testOnlyTheOwnerAndAcceptedAdminsCorrectASale(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale(['unitPrice' => 950000.0]));

        $granted = $expectedOutcome === self::GRANTED;
        self::assertSame(
            [$expectedOutcome, $granted ? 950000.0 : self::SABRE_SALE_UNIT_PRICE, $granted ? 1 : 0, $granted ? 1 : 0],
            [$outcome, $this->sabreSale->getUnitPrice(), $this->flushes, \count($this->publishedUpdates)],
        );
    }

    public function testCorrectionThroughAnotherProjectOfTheOwnerIsNotFoundAndKeepsTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $status = $this->httpStatusOf(fn () => $this->correctSabreSale(['unitPrice' => 950000.0], $this->sabreSaleUri(projectId: $this->hecateBatch->getId())));

        self::assertSame([404, [900000.0, 2, 1800000.0], 0], [$status, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    public function testCorrectionOfTheVenueMovesTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['venue' => 'Dodixie IX - Moon 20']);

        self::assertSame(['Dodixie IX - Moon 20', 'Dodixie IX - Moon 20', 1800000.0], [$sale->venue, $this->sabreSale->getVenue(), $sale->totalPrice]);
    }

    #[DataProvider('profilesRefusedTheCorrection')]
    public function testProcessorRefusesTheCorrectionOfANonAdminWithoutRelyingOnTheProvider(string $profile): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        $outcome = $this->outcomeOf(fn () => $this->correctThroughTheProcessorOnly($this->sabreSaleUri()));

        self::assertSame([self::ADMIN_OR_OWNER_ONLY, [900000.0, 2, 1800000.0], 0, []], [$outcome, $this->persistedSabreSaleAmounts(), $this->flushes, $this->publishedUpdates]);
    }

    public static function profilesRefusedTheCorrection(): iterable
    {
        yield 'accepted member' => [self::ACCEPTED_MEMBER];
        yield 'stranger' => [self::STRANGER];
    }

    public function testProcessorRefusesACorrectionWithoutUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $outcome = $this->outcomeOf(fn () => $this->correctThroughTheProcessorOnly($this->sabreSaleUri()));

        self::assertSame([self::UNAUTHORIZED, [900000.0, 2, 1800000.0], 0], [$outcome, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    #[DataProvider('missingUriVariables')]
    public function testCorrectionWithoutProjectOrSaleIdentifierIsNotFound(string $missingUriVariable): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));
        $uriVariables = $this->sabreSaleUri();
        unset($uriVariables[$missingUriVariable]);

        $status = $this->httpStatusOf(fn () => $this->correctThroughTheProcessorOnly($uriVariables));

        self::assertSame([404, [900000.0, 2, 1800000.0], 0], [$status, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    public static function missingUriVariables(): iterable
    {
        yield 'project id' => ['projectId'];
        yield 'sale id' => ['id'];
    }

    #[DataProvider('uriVariablesThatAreNotUuids')]
    public function testCorrectionThroughAnIdentifierThatIsNotAUuidIsNotFoundAndKeepsTheSale(string $uriVariable): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));
        $input = new UpdateSaleInput();
        $input->unitPrice = 950000.0;

        $status = $this->httpStatusOf(fn () => $this->processor->process($input, new Patch(), [...$this->sabreSaleUri(), $uriVariable => 'abc']));

        self::assertSame([404, [900000.0, 2, 1800000.0], 0], [$status, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    public static function uriVariablesThatAreNotUuids(): iterable
    {
        yield 'sale id' => ['id'];
        yield 'project id' => ['projectId'];
    }

    public function testCorrectionOnACompletedProjectIsRefusedAndKeepsTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT));

        $refusal = null;
        try {
            $this->correctSabreSale(['quantity' => 3, 'unitPrice' => 950000.0]);
        } catch (BadRequestHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame([[900000.0, 2, 1800000.0], 0, []], [$this->persistedSabreSaleAmounts(), $this->flushes, $this->publishedUpdates]);
    }

    #[Group('issue-241')]
    public function testAdminNamesHisCharacterAsSellerOfASaleWithoutSellerAndTheTotalStays(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain));

        $sale = $this->correctSabreSale(['sellerCharacterId' => self::A_MAIN_EVE_CHARACTER_ID]);

        self::assertSame(
            [self::A_MAIN_EVE_CHARACTER_ID, 'A-Main', 2, 900000.0, 1800000.0, 1],
            [$sale->sellerCharacterId, $sale->sellerCharacterName, $sale->quantity, $sale->unitPrice, $sale->totalPrice, $this->flushes],
        );
        self::assertSame($this->aMain, $this->sabreSale->getSeller());
    }

    #[Group('issue-241')]
    public function testCorrectionOfTheSellerPublishesTheSameSaleUpdatedData(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain));

        $this->correctSabreSale(['sellerCharacterId' => self::A_MAIN_EVE_CHARACTER_ID]);

        self::assertSame(
            [[
                'action' => 'sale_updated',
                'data' => ['saleId' => $this->sabreSale->getId()->toRfc4122(), 'typeName' => 'Sabre', 'quantity' => 2, 'totalPrice' => 1800000.0],
            ]],
            $this->actionsAndDataOf($this->publishedUpdates),
        );
    }

    #[Group('issue-241')]
    public function testOwnerMovesTheSaleFromAnAdminCharacterToHisAlt(): void
    {
        $this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain);
        $this->sabreSale->setSeller($this->aMain);
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::OWNER), $this->oMain, $this->oAlt));

        $sale = $this->correctSabreSale(['sellerCharacterId' => self::O_ALT_EVE_CHARACTER_ID]);

        self::assertSame([self::O_ALT_EVE_CHARACTER_ID, 'O-Alt'], [$sale->sellerCharacterId, $sale->sellerCharacterName]);
        self::assertSame($this->oAlt, $this->sabreSale->getSeller());
    }

    public static function correctionsThatLeaveTheSellerOut(): iterable
    {
        yield 'A8 null seller' => [['sellerCharacterId' => null], 1800000.0];
        yield 'unit price only' => [['unitPrice' => 950000.0], 1900000.0];
    }

    #[Group('issue-241')]
    #[DataProvider('correctionsThatLeaveTheSellerOut')]
    public function testCorrectionThatLeavesTheSellerOutKeepsTheSellerOfTheSale(array $changes, float $expectedTotalPrice): void
    {
        $this->sabreSale->setSeller($this->oAlt);
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain));

        $sale = $this->correctSabreSale($changes);

        self::assertSame(
            [self::O_ALT_EVE_CHARACTER_ID, 'O-Alt', $expectedTotalPrice],
            [$sale->sellerCharacterId, $sale->sellerCharacterName, $sale->totalPrice],
        );
        self::assertSame($this->oAlt, $this->sabreSale->getSeller());
    }

    public static function sellerCorrectionsNamingACharacterOfAnotherUser(): iterable
    {
        yield 'A9 main of the owner' => [['sellerCharacterId' => self::O_MAIN_EVE_CHARACTER_ID]];
        yield 'character unknown to the application' => [['sellerCharacterId' => self::UNKNOWN_EVE_CHARACTER_ID]];
        yield 'main of the owner with a new unit price' => [['unitPrice' => 950000.0, 'sellerCharacterId' => self::O_MAIN_EVE_CHARACTER_ID]];
    }

    #[Group('issue-241')]
    #[DataProvider('sellerCorrectionsNamingACharacterOfAnotherUser')]
    public function testSellerCorrectionNamingACharacterOfAnotherUserIsRefusedAndKeepsTheSale(array $changes): void
    {
        $this->sabreSale->setSeller($this->oAlt);
        $this->withCharacters($this->userWithProfile(self::OWNER), $this->oMain, $this->oAlt);
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain));

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale($changes));

        self::assertSame(
            [self::SELLER_OF_ANOTHER_USER, [900000.0, 2, 1800000.0], 0, []],
            [$outcome, $this->persistedSabreSaleAmounts(), $this->flushes, $this->publishedUpdates],
        );
        self::assertSame($this->oAlt, $this->sabreSale->getSeller());
    }

    #[Group('issue-241')]
    public function testMemberNamingHisOwnCharacterAsSellerIsStillRefusedTheCorrection(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_MEMBER), $this->mMain));

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale(['sellerCharacterId' => self::M_MAIN_EVE_CHARACTER_ID]));

        self::assertSame([self::ADMIN_OR_OWNER_ONLY, 0, []], [$outcome, $this->flushes, $this->publishedUpdates]);
    }

    #[Group('issue-241')]
    public function testSellerCorrectionNamingAForeignCharacterOnACompletedProjectIsRefusedAsCompleted(): void
    {
        $this->security->method('getUser')->willReturn(
            $this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT), $this->aMain),
        );

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale(['sellerCharacterId' => self::O_MAIN_EVE_CHARACTER_ID]));

        self::assertSame(['400 Cannot correct a sale on a completed project', 0], [$outcome, $this->flushes]);
    }

    public static function correctionsOfAValueTakenFromTheWalletTransaction(): iterable
    {
        yield 'P1 unit price' => [['unitPrice' => 60_000_000.0]];
        yield 'P5 seller among the characters of the owner' => [['sellerCharacterId' => self::O_ALT_EVE_CHARACTER_ID]];
        yield 'P5 sale date' => [['soldAt' => '2026-10-25T10:00:00+00:00']];
        yield 'P5 type identical to the one of the transaction' => [['typeId' => self::SABRE_TYPE_ID_OF_T1]];
        yield 'type typed by name' => [['typeId' => 0]];
        yield 'unit price with a quantity within the transaction' => [['quantity' => 2, 'unitPrice' => 60_000_000.0]];
        yield 'free unit price' => [['unitPrice' => 0.0]];
    }

    #[Group('issue-241')]
    #[DataProvider('correctionsOfAValueTakenFromTheWalletTransaction')]
    public function testCorrectionOfAValueTakenFromTheWalletTransactionIsRefusedAndKeepsTheLinkedSale(array $changes): void
    {
        $t1 = $this->linkTheSabreSaleToT1();
        $this->actAsTheOwnerWithHisCharacters();

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale($changes));

        self::assertSame(
            [self::LINKED_SALE_REFUSAL, [61_250_000.0, 3, 183_750_000.0], self::SABRE_TYPE_ID_OF_T1, '2026-10-24T21:05:00+00:00', 0, []],
            [$outcome, $this->persistedSabreSaleAmounts(), $this->sabreSale->getTypeId(), $this->sabreSale->getSoldAt()->format(\DateTimeInterface::ATOM), $this->flushes, $this->publishedUpdates],
        );
        self::assertSame([$this->oMain, $t1], [$this->sabreSale->getSeller(), $this->sabreSale->getWalletTransaction()]);
    }

    public static function quantitiesWithinT1(): iterable
    {
        yield 'P2 two of the three units' => [2, 122_500_000.0];
        yield 'every unit of the transaction' => [3, 183_750_000.0];
        yield 'a single unit' => [1, 61_250_000.0];
    }

    #[Group('issue-241')]
    #[DataProvider('quantitiesWithinT1')]
    public function testQuantityOfALinkedSaleIsCorrectedWithinTheQuantityOfItsTransaction(int $quantity, float $expectedTotalPrice): void
    {
        $t1 = $this->linkTheSabreSaleToT1();
        $this->actAsTheOwnerWithHisCharacters();

        $sale = $this->correctSabreSale(['quantity' => $quantity]);

        self::assertSame(
            [$quantity, 61_250_000.0, $expectedTotalPrice, true, self::O_MAIN_EVE_CHARACTER_ID, 1],
            [$sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->linkedToWalletTransaction, $sale->sellerCharacterId, $this->flushes],
        );
        self::assertSame($t1, $this->sabreSale->getWalletTransaction());
    }

    #[Group('issue-241')]
    public function testP3QuantityAboveTheTransactionIsRefusedAndKeepsTheLinkedSale(): void
    {
        $this->linkTheSabreSaleToT1();
        $this->actAsTheOwnerWithHisCharacters();

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale(['quantity' => 4]));

        self::assertSame(
            ['400 The quantity exceeds the quantity of the transaction.', [61_250_000.0, 3, 183_750_000.0], 0, []],
            [$outcome, $this->persistedSabreSaleAmounts(), $this->flushes, $this->publishedUpdates],
        );
    }

    #[Group('issue-241')]
    public function testP4VenueAndItemNameOfALinkedSaleStayFree(): void
    {
        $this->linkTheSabreSaleToT1();
        $this->actAsTheOwnerWithHisCharacters();

        $sale = $this->correctSabreSale(['venue' => 'Jita 4-4', 'typeName' => 'Sabre (fleet hull)']);

        self::assertSame(
            ['Jita 4-4', 'Sabre (fleet hull)', 183_750_000.0, true, 1],
            [$sale->venue, $sale->typeName, $sale->totalPrice, $sale->linkedToWalletTransaction, $this->flushes],
        );
    }

    #[Group('issue-241')]
    public function testValuesOfTheTransactionLeftNullDoNotBlockTheCorrectionOfALinkedSale(): void
    {
        $this->linkTheSabreSaleToT1();
        $this->actAsTheOwnerWithHisCharacters();

        $sale = $this->correctSabreSale(['typeId' => null, 'unitPrice' => null, 'soldAt' => null, 'sellerCharacterId' => null, 'quantity' => 2]);

        self::assertSame(
            [self::SABRE_TYPE_ID_OF_T1, 2, 61_250_000.0, 122_500_000.0, '2026-10-24T21:05:00+00:00', 'O-Main'],
            [$sale->typeId, $sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->soldAt, $sale->sellerCharacterName],
        );
    }

    #[Group('issue-241')]
    public function testSaleWhoseWalletTransactionVanishedIsCorrectedFreely(): void
    {
        $this->sabreSale->setTypeId(self::SABRE_TYPE_ID_OF_T1)->setQuantity(3)->setUnitPrice(61_250_000.0)->recalculateTotalPrice();
        $this->actAsTheOwnerWithHisCharacters();

        $sale = $this->correctSabreSale([
            'unitPrice' => 60_000_000.0,
            'quantity' => 4,
            'soldAt' => '2026-10-25T10:00:00+00:00',
            'sellerCharacterId' => self::O_ALT_EVE_CHARACTER_ID,
        ]);

        self::assertSame(
            [4, 60_000_000.0, 240_000_000.0, '2026-10-25T10:00:00+00:00', 'O-Alt', false],
            [$sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->soldAt, $sale->sellerCharacterName, $sale->linkedToWalletTransaction],
        );
    }

    private function linkTheSabreSaleToT1(): CachedWalletTransaction
    {
        $t1 = (new CachedWalletTransaction())
            ->setCharacter($this->oMain)
            ->setTransactionId(6_100_000_001)
            ->setIsBuy(false)
            ->setTypeId(self::SABRE_TYPE_ID_OF_T1)
            ->setQuantity(3)
            ->setUnitPrice(61_250_000.0)
            ->setDate(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'))
            ->setLocationId(60003760)
            ->setClientId(2119000001);
        (new \ReflectionProperty(CachedWalletTransaction::class, 'id'))->setValue($t1, Uuid::v4());

        $this->sabreSale
            ->setTypeId(self::SABRE_TYPE_ID_OF_T1)
            ->setQuantity(3)
            ->setUnitPrice(61_250_000.0)
            ->recalculateTotalPrice()
            ->setVenue(null)
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'))
            ->setSeller($this->oMain)
            ->setWalletTransaction($t1);

        return $t1;
    }

    private function actAsTheOwnerWithHisCharacters(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::OWNER), $this->oMain, $this->oAlt));
    }

    private function correctSabreSale(array $changes, ?array $uriVariables = null): GroupIndustrySaleResource
    {
        $input = new UpdateSaleInput();
        foreach ($changes as $field => $value) {
            $input->{$field} = $value;
        }

        $operation = new Patch();
        $uriVariables ??= $this->sabreSaleUri();
        $previousSale = $this->provider->provide($operation, $uriVariables);

        return $this->processor->process($input, $operation, $uriVariables, ['previous_data' => $previousSale]);
    }

    private function correctThroughTheProcessorOnly(array $uriVariables): GroupIndustrySaleResource
    {
        $input = new UpdateSaleInput();
        $input->unitPrice = 950000.0;

        return $this->processor->process($input, new Patch(), $uriVariables);
    }

    private function persistedSabreSaleAmounts(): array
    {
        return [$this->sabreSale->getUnitPrice(), $this->sabreSale->getQuantity(), $this->sabreSale->getTotalPrice()];
    }
}
