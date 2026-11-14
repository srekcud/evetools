<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\PurchaseSuggestionResource;
use App\ApiResource\Industry\WalletReadingResource;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Sync\WalletReadingStateReader;
use App\State\Provider\Industry\PurchaseSuggestionProvider;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurchaseSuggestionProvider::class)]
#[Group('issue-362')]
#[AllowMockObjectsWithoutExpectations]
final class PurchaseSuggestionProviderTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const int JITA_4_4_STATION_ID = 60_003_760;

    private array $buysByCharacterName = [];

    private array $storedPurchases = [];

    private array $buyLookups = [];

    private array $storedWalletReadings = [];

    private User $member;

    private Character $memberCharacter;

    private User $outsider;

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->member = new User();
        $this->memberCharacter = $this->characterOf($this->member, 'Cora Vance');
        $this->participate($this->ishtarProject, $this->member, ParticipantRole::Member);
        $this->outsider = new User();
        $this->characterOf($this->outsider, 'Elia Varr');
    }

    public function testBuysOfTheComponentLinesAndOfTheMaterialLinesAreBothSuggested(): void
    {
        $this->buy($this->ownerAltCharacter, 6_362_000_001, self::RAM_TYPE_ID, 50, 3_040.5);
        $this->buy($this->ownerMainCharacter, 6_362_000_002, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $result = $this->suggestionsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [
                2,
                [
                    [6_362_000_002, self::TRITANIUM_TYPE_ID, 'Tritanium', 1_000, 4_400.0, 'Aria Solberg', false],
                    [6_362_000_001, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 50, 152_025.0, 'Aria Alt', false],
                ],
            ],
            [$result->totalCount, $this->fieldsOf($result->suggestions)],
        );
        self::assertSame(
            [['Aria Solberg', [self::TRITANIUM_TYPE_ID, self::RAM_TYPE_ID]], ['Aria Alt', [self::TRITANIUM_TYPE_ID, self::RAM_TYPE_ID]]],
            array_map(static fn (array $lookup): array => [$lookup[0], $lookup[1]], $this->buyLookups),
        );
    }

    public function testBuyMadeBeforeTheCreationOfTheProjectIsNotSuggested(): void
    {
        $this->buy($this->ownerAltCharacter, 6_750_000_101, self::RAM_TYPE_ID, 50, 3_040.5, '2026-10-31T23:59:59+00:00');
        $this->buy($this->ownerAltCharacter, 6_750_000_102, self::RAM_TYPE_ID, 20, 3_040.5, '2026-11-01T10:00:00+00:00');

        $result = $this->suggestionsFor($this->owner, $this->ishtarProject);

        self::assertSame([6_750_000_102], array_map(static fn (PurchaseSuggestionResource $suggestion): int => $suggestion->transactionId, $result->suggestions));
        self::assertSame(['2026-11-01T10:00:00+00:00'], array_unique(array_column($this->buyLookups, 2)));
    }

    public function testBuyLinkedToALineOfTheProjectIsMarkedAlreadyLinked(): void
    {
        $ramBuy = $this->buy($this->ownerAltCharacter, 6_362_000_011, self::RAM_TYPE_ID, 50, 3_040.5);
        $this->linkInAPurchase($this->ramLine, $ramBuy);

        $result = $this->suggestionsFor($this->owner, $this->ishtarProject);

        self::assertSame([[6_362_000_011, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 50, 152_025.0, 'Aria Alt', true]], $this->fieldsOf($result->suggestions));
    }

    public function testProjectWithoutComponentLineSuggestsTheBuysOfItsMaterialLinesAndHidesThoseLinkedToAnotherProject(): void
    {
        $tritaniumBuyLinkedToIshtar = $this->buy($this->ownerMainCharacter, 6_362_000_021, self::TRITANIUM_TYPE_ID, 1_000, 4.4);
        $this->buy($this->ownerMainCharacter, 6_362_000_022, self::TRITANIUM_TYPE_ID, 2_500, 4.2);
        $this->linkInAPurchase($this->tritaniumLine, $tritaniumBuyLinkedToIshtar);

        $result = $this->suggestionsFor($this->owner, $this->hecateProject);

        self::assertSame(
            [1, [[6_362_000_022, self::TRITANIUM_TYPE_ID, 'Tritanium', 2_500, 10_500.0, 'Aria Solberg', false]]],
            [$result->totalCount, $this->fieldsOf($result->suggestions)],
        );
    }

    #[Group('issue-916')]
    public function testWalletReadingsOfTheCharactersOfTheOwnerAreServedEvenWithoutAnyBuy(): void
    {
        $this->ownerMainCharacter->setEveToken((new EveToken())->setScopes(['esi-wallet.read_character_wallet.v1']));
        $this->storedWalletReadings[] = (new CharacterWalletReading())
            ->setCharacter($this->ownerMainCharacter)
            ->setReadAt(new \DateTimeImmutable('2026-11-13T20:04:10+00:00'))
            ->setNextReadAt(new \DateTimeImmutable('2026-11-13T21:04:00+00:00'));

        $result = $this->suggestionsFor($this->owner, $this->ishtarProject);

        self::assertSame([0, []], [$result->totalCount, $result->suggestions]);
        self::assertSame(
            [
                ['Aria Solberg', 'read', '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:00+00:00'],
                ['Aria Alt', 'no_token', null, null],
            ],
            $this->walletReadingFieldsOf($result->walletReadings),
        );
    }

    #[Group('issue-916')]
    public function testWalletReadingsAreServedWhenTheProjectHasNoPurchasableLine(): void
    {
        $this->hecateTritaniumLine->setIsJob(true)->setJobGroup('final');
        $this->ownerAltCharacter->setEveToken((new EveToken())->setScopes(['esi-wallet.read_character_wallet.v1']));

        $result = $this->suggestionsFor($this->owner, $this->hecateProject);

        self::assertSame([0, []], [$result->totalCount, $result->suggestions]);
        self::assertSame(
            [['Aria Solberg', 'no_token', null, null], ['Aria Alt', 'never_read', null, null]],
            $this->walletReadingFieldsOf($result->walletReadings),
        );
    }

    #[Group('issue-916')]
    public function testWalletReadingsAccompanyTheSuggestionsOfTheBuys(): void
    {
        $this->buy($this->ownerMainCharacter, 6_916_000_001, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $result = $this->suggestionsFor($this->owner, $this->ishtarProject);

        self::assertSame([1, ['Aria Solberg', 'Aria Alt']], [$result->totalCount, array_column($this->walletReadingFieldsOf($result->walletReadings), 0)]);
    }

    #[Group('issue-981')]
    public function testAcceptedMemberIsSuggestedOnlyTheBuysOfHisOwnCharacters(): void
    {
        $this->buy($this->ownerMainCharacter, 6_981_000_001, self::TRITANIUM_TYPE_ID, 1_000, 4.4);
        $this->buy($this->memberCharacter, 6_981_000_002, self::TRITANIUM_TYPE_ID, 2_500, 4.2);

        $result = null;
        self::assertSame(
            self::GRANTED,
            $this->outcomeOf(function () use (&$result): void {
                $result = $this->suggestionsFor($this->member, $this->ishtarProject);
            }),
        );

        self::assertSame(
            [1, [[6_981_000_002, self::TRITANIUM_TYPE_ID, 'Tritanium', 2_500, 10_500.0, 'Cora Vance', false]]],
            [$result->totalCount, $this->fieldsOf($result->suggestions)],
        );
        self::assertSame(['Cora Vance'], array_column($this->buyLookups, 0));
    }

    #[Group('issue-981')]
    public function testBuyOfAMemberLinkedToALineOfTheProjectIsMarkedAlreadyLinkedForHim(): void
    {
        $buy = $this->buy($this->memberCharacter, 6_981_000_003, self::TRITANIUM_TYPE_ID, 2_500, 4.2);
        $this->linkInAPurchase($this->tritaniumLine, $buy);

        $result = null;
        self::assertSame(
            self::GRANTED,
            $this->outcomeOf(function () use (&$result): void {
                $result = $this->suggestionsFor($this->member, $this->ishtarProject);
            }),
        );

        self::assertSame([[6_981_000_003, self::TRITANIUM_TYPE_ID, 'Tritanium', 2_500, 10_500.0, 'Cora Vance', true]], $this->fieldsOf($result->suggestions));
    }

    #[Group('issue-981')]
    public function testUserWhoIsNotAParticipantIsDeniedWithoutReadingTheWallet(): void
    {
        self::assertSame(
            ['403 You do not have access to this project', []],
            [$this->outcomeOf(fn () => $this->suggestionsFor($this->outsider, $this->ishtarProject)), $this->buyLookups],
        );
    }

    private function suggestionsFor(User $user, IndustryProject $project): mixed
    {
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByTypeIds')->willReturnCallback(
            function (Character $character, array $typeIds, ?\DateTimeImmutable $since = null): array {
                $sortedTypeIds = array_values($typeIds);
                sort($sortedTypeIds);
                $this->buyLookups[] = [$character->getName(), $sortedTypeIds, $since?->format('c')];

                return array_values(array_filter(
                    $this->buysByCharacterName[$character->getName()] ?? [],
                    static fn (CachedWalletTransaction $transaction): bool => in_array($transaction->getTypeId(), $typeIds, true)
                        && ($since === null || $transaction->getDate() >= $since),
                ));
            },
        );
        $purchaseRepository = $this->createStub(IndustryProjectPurchaseRepository::class);
        $purchaseRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->storedPurchases,
                static fn (IndustryProjectPurchase $purchase): bool => in_array($purchase->getTransaction(), $criteria['transaction'], true),
            )),
        );
        $readingRepository = $this->createStub(CharacterWalletReadingRepository::class);
        $readingRepository->method('findByCharacters')->willReturnCallback(fn (): array => $this->storedWalletReadings);
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveTypeName')->willReturnCallback(
            static fn (int $typeId): ?string => [self::RAM_TYPE_ID => 'R.A.M.- Starship Tech', self::TRITANIUM_TYPE_ID => 'Tritanium'][$typeId] ?? null,
        );

        return (new PurchaseSuggestionProvider(
            $this->securityOf($user),
            $this->projectRepository(),
            $transactionRepository,
            $calculationService,
            $purchaseRepository,
            $this->accessChecker(),
            new WalletReadingStateReader($readingRepository),
        ))->provide(new Get(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    private function buy(Character $character, int $transactionId, int $typeId, int $quantity, float $unitPrice, string $date = '2026-11-02T12:00:00+00:00'): CachedWalletTransaction
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(self::JITA_4_4_STATION_ID)
            ->setDate(new \DateTimeImmutable($date));
        $this->buysByCharacterName[$character->getName()][] = $transaction;

        return $transaction;
    }

    private function linkInAPurchase(IndustryProjectBomLine $line, CachedWalletTransaction $transaction): void
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTransaction($transaction)
            ->setTypeId($transaction->getTypeId())
            ->setQuantity($transaction->getQuantity())
            ->setUnitPrice($transaction->getUnitPrice())
            ->setTotalPrice($transaction->getTotalPrice())
            ->setSource('esi_wallet');
        $line->addPurchase($purchase);
        $this->storedPurchases[] = $purchase;
    }

    private function walletReadingFieldsOf(array $walletReadings): array
    {
        return array_map(
            static fn (WalletReadingResource $reading): array => [$reading->characterName, $reading->status, $reading->readAt, $reading->nextReadAt],
            $walletReadings,
        );
    }

    private function fieldsOf(array $suggestions): array
    {
        return array_map(
            static fn (PurchaseSuggestionResource $suggestion): array => [
                $suggestion->transactionId,
                $suggestion->typeId,
                $suggestion->typeName,
                $suggestion->quantity,
                $suggestion->totalPrice,
                $suggestion->characterName,
                $suggestion->alreadyLinked,
            ],
            $suggestions,
        );
    }
}
