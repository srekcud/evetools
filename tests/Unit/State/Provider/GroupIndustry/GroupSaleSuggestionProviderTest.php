<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupSaleSuggestionResource;
use App\Entity\CachedWalletTransaction;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleSuggestionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupSaleSuggestionProvider::class)]
#[Group('issue-241')]
final class GroupSaleSuggestionProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSellers;
    use SabreBatchWalletTransactions;

    private Security&Stub $security;
    private GroupSaleSuggestionProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->createSabreBatchSellers();
        $this->createSabreBatchWalletTransactions();
        $this->security = $this->createStub(Security::class);

        $this->provider = new GroupSaleSuggestionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new GroupProjectAccessChecker($this->sabreBatchMemberRepository()),
            $this->walletTransactionRepository(),
        );
    }

    public function testOwnerIsOfferedOnlyHisUnlinkedSalesOfAProjectItemSinceTheProjectCreationNewestFirst(): void
    {
        $this->actAsTheOwner();

        self::assertSame([$this->t7->getId()?->toRfc4122(), $this->t1->getId()?->toRfc4122()], $this->suggestedTransactionIds());
    }

    public function testSuggestionOfT1CarriesTheValuesOfItsTransactionAndTheManualSaleVAsPossibleDuplicate(): void
    {
        $this->actAsTheOwner();

        self::assertSame(
            $this->sortedByField([
                'transactionId' => $this->t1->getId()?->toRfc4122(),
                'typeId' => self::SABRE_TYPE_ID,
                'typeName' => 'Sabre',
                'quantity' => 3,
                'unitPrice' => 61_250_000.0,
                'totalPrice' => 183_750_000.0,
                'date' => '2026-10-24T21:05:00+00:00',
                'sellerCharacterId' => self::O_MAIN_EVE_CHARACTER_ID,
                'sellerCharacterName' => 'O-Main',
                'locationId' => self::JITA_STATION_ID,
                'possibleDuplicateSaleIds' => [$this->manualSaleV->getId()?->toRfc4122()],
            ]),
            $this->suggestionOf($this->t1),
        );
    }

    public function testSuggestionOfT7IsSoldByTheAltWithoutPossibleDuplicate(): void
    {
        $this->actAsTheOwner();

        self::assertSame(
            $this->sortedByField([
                'transactionId' => $this->t7->getId()?->toRfc4122(),
                'typeId' => self::SABRE_TYPE_ID,
                'typeName' => 'Sabre',
                'quantity' => 12,
                'unitPrice' => 60_000_000.0,
                'totalPrice' => 720_000_000.0,
                'date' => '2026-10-26T11:45:00+00:00',
                'sellerCharacterId' => self::O_ALT_EVE_CHARACTER_ID,
                'sellerCharacterName' => 'O-Alt',
                'locationId' => self::RENS_STATION_ID,
                'possibleDuplicateSaleIds' => [],
            ]),
            $this->suggestionOf($this->t7),
        );
    }

    public function testAdminIsOfferedOnlyTheSaleOfHisOwnCharacter(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->aMain));

        $suggestions = $this->provideSuggestions();

        self::assertSame(
            [[$this->t5->getId()?->toRfc4122(), self::A_MAIN_EVE_CHARACTER_ID, 'A-Main', 2, 122_000_000.0, self::PERIMETER_STRUCTURE_ID, []]],
            array_map(
                static fn (GroupSaleSuggestionResource $suggestion): array => [
                    $suggestion->transactionId,
                    $suggestion->sellerCharacterId,
                    $suggestion->sellerCharacterName,
                    $suggestion->quantity,
                    $suggestion->totalPrice,
                    $suggestion->locationId,
                    $suggestion->possibleDuplicateSaleIds,
                ],
                $suggestions,
            ),
        );
    }

    public function testTransactionOfAnyItemOfTheProjectIsOffered(): void
    {
        $this->addSabreBatchItem(self::ISHTAR_TYPE_ID, 'Ishtar', 8);
        $this->actAsTheOwner();

        self::assertSame(
            [
                [$this->t7->getId()?->toRfc4122(), 'Sabre'],
                [$this->t1->getId()?->toRfc4122(), 'Sabre'],
                [$this->t3->getId()?->toRfc4122(), 'Ishtar'],
            ],
            array_map(
                static fn (GroupSaleSuggestionResource $suggestion): array => [$suggestion->transactionId, $suggestion->typeName],
                $this->provideSuggestions(),
            ),
        );
    }

    public function testTransactionIsOfferedOnceWhenTwoItemsOfTheProjectShareItsType(): void
    {
        $this->addSabreBatchItem(self::SABRE_TYPE_ID, 'Sabre', 8);
        $this->actAsTheOwner();

        self::assertSame([$this->t7->getId()?->toRfc4122(), $this->t1->getId()?->toRfc4122()], $this->suggestedTransactionIds());
    }

    public function testSaleDatedAtTheVeryCreationOfTheProjectIsOffered(): void
    {
        $saleAtCreation = $this->walletTransaction(6_100_000_008, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_250_000.0, self::SABRE_BATCH_CREATED_AT, self::JITA_STATION_ID);
        $this->walletTransaction(6_100_000_009, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_750_000.0, '2026-10-20T09:59:59+00:00', self::JITA_STATION_ID);
        $this->actAsTheOwner();

        self::assertSame(
            [$this->t7->getId()?->toRfc4122(), $this->t1->getId()?->toRfc4122(), $saleAtCreation->getId()?->toRfc4122()],
            $this->suggestedTransactionIds(),
        );
    }

    public function testTransactionLinkedToASaleOfThisProjectIsNoLongerOffered(): void
    {
        $this->addSabreBatchSale(self::SABRE_TYPE_ID, 3, 61_250_000.0)->setWalletTransaction($this->t1);
        $this->actAsTheOwner();

        self::assertSame([$this->t7->getId()?->toRfc4122()], $this->suggestedTransactionIds());
    }

    public function testPossibleDuplicatesAreTheUnlinkedSalesOfTheProjectOfSameQuantityAndUnitPriceWhateverTheirType(): void
    {
        $saleTypedAsSabre = $this->addSabreBatchSale(self::SABRE_TYPE_ID, 3, 61_250_000.0);
        $this->addSabreBatchSale(0, 3, 61_000_000.0);
        $this->addSabreBatchSale(0, 2, 61_250_000.0);
        $this->addSabreBatchSale(0, 4, 61_250_000.0);
        $this->addSabreBatchSale(0, 3, 61_250_000.01);
        $this->addSabreBatchSale(self::SABRE_TYPE_ID, 3, 61_250_000.0)->setWalletTransaction(
            $this->walletTransaction(6_100_000_010, $this->oMain, false, self::SABRE_TYPE_ID, 3, 61_250_000.0, '2026-10-21T08:00:00+00:00', self::JITA_STATION_ID),
        );
        $this->actAsTheOwner();

        $expectedDuplicates = [$this->manualSaleV->getId()?->toRfc4122(), $saleTypedAsSabre->getId()?->toRfc4122()];
        $possibleDuplicates = $this->suggestionOf($this->t1)['possibleDuplicateSaleIds'];
        sort($expectedDuplicates);
        sort($possibleDuplicates);

        self::assertSame($expectedDuplicates, $possibleDuplicates);
    }

    public function testSaleOfAnotherProjectWithTheSameAmountsIsNoPossibleDuplicate(): void
    {
        $cutlassSale = $this->saleOfAnotherProjectLinkedToT6;
        $cutlassSale->setQuantity(12)->setUnitPrice(60_000_000.0)->recalculateTotalPrice();
        $this->actAsTheOwner();

        self::assertSame([], $this->suggestionOf($this->t7)['possibleDuplicateSaleIds']);
    }

    public function testUserWithoutAnyCachedSaleIsOfferedNothing(): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile(self::ACCEPTED_ADMIN), $this->sellerCharacter(2120000031, 'A-Alt')));

        self::assertSame([], $this->provideSuggestions());
    }

    public function testSuggestionsStayReadableOnACompletedProject(): void
    {
        $this->sabreBatch->setStatus(GroupProjectStatus::Completed);
        $this->actAsTheOwner();

        self::assertSame([$this->t7->getId()?->toRfc4122(), $this->t1->getId()?->toRfc4122()], $this->suggestedTransactionIds());
    }

    public static function suggestionAccessMatrix(): iterable
    {
        yield 'L10 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'removed member' => [self::REMOVED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
        yield 'accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'accepted admin of a completed project' => [self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT, self::GRANTED];
    }

    #[DataProvider('suggestionAccessMatrix')]
    public function testOnlyTheOwnerAndAcceptedAdminsAreOfferedTheWalletTransactions(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->withCharacters($this->userWithProfile($profile), $this->mMain));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->provideSuggestions()));
    }

    public function testSuggestionsOfAnUnknownProjectAreNotFound(): void
    {
        $this->actAsTheOwner();

        self::assertSame(self::PROJECT_NOT_FOUND, $this->outcomeOf(fn () => $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toRfc4122()])));
    }

    public function testSuggestionsWithoutUserAreUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->outcomeOf(fn () => $this->provideSuggestions()));
    }

    private function actAsTheOwner(): User
    {
        $owner = $this->withCharacters($this->userWithProfile(self::OWNER), $this->oMain, $this->oAlt);
        $this->security->method('getUser')->willReturn($owner);

        return $owner;
    }

    private function provideSuggestions(): array
    {
        return $this->provider->provide(new GetCollection(), ['projectId' => $this->sabreBatch->getId()?->toRfc4122()]);
    }

    private function suggestedTransactionIds(): array
    {
        return array_map(
            static fn (GroupSaleSuggestionResource $suggestion): string => $suggestion->transactionId,
            $this->provideSuggestions(),
        );
    }

    private function suggestionOf(CachedWalletTransaction $transaction): array
    {
        foreach ($this->provideSuggestions() as $suggestion) {
            if ($suggestion->transactionId === $transaction->getId()?->toRfc4122()) {
                return $this->sortedByField(get_object_vars($suggestion));
            }
        }

        self::fail('no suggestion for the transaction ' . $transaction->getTransactionId());
    }

    private function sortedByField(array $fields): array
    {
        ksort($fields);

        return $fields;
    }
}
