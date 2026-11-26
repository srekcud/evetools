<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPaidPriceCalculator::class)]
#[Group('issue-1396')]
final class PlanetaryPaidPriceCalculatorTest extends TestCase
{
    private const int WATER = 3645;
    private const int BACTERIA = 2393;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int TRITANIUM = 34;
    private const string NOW = '2026-11-26T10:00:00+00:00';
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    private int $nextTransactionId = 1;

    public function testThePaidPriceIsTheWeightedAverageOfThePurchasesOfAllTheCharacters(): void
    {
        $first = $this->character(2112000001, 'Pilot One');
        $second = $this->character(2112000002, 'Pilot Two');
        $report = $this->report(
            [
                $this->purchase($first, self::WATER, 10, 1_000.0, '2026-11-20T12:00:00+00:00'),
                $this->purchase($second, self::WATER, 30, 2_000.0, '2026-11-21T12:00:00+00:00'),
            ],
            [$first, $second],
        );

        $this->assertSame([1_750.0, 40, 2], $this->figuresOf($report, self::WATER));
    }

    public function testThePaidPriceIsRoundedToTwoDecimals(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report(
            [
                $this->purchase($pilot, self::WATER, 1, 10.0, '2026-11-20T12:00:00+00:00'),
                $this->purchase($pilot, self::WATER, 2, 10.01, '2026-11-21T12:00:00+00:00'),
            ],
            [$pilot],
        );

        $this->assertSame([10.01, 3, 2], $this->figuresOf($report, self::WATER));
    }

    public function testATypeWithoutPurchaseHasAnUnknownPriceNeverZero(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report([$this->purchase($pilot, self::WATER, 5, 100.0, '2026-11-20T12:00:00+00:00')], [$pilot]);

        $this->assertSame([null, 0, 0], $this->figuresOf($report, self::COOLANT));
    }

    public function testAPurchaseExactlyNinetyDaysOldCountsAndOneSecondOlderDoesNot(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report(
            [
                $this->purchase($pilot, self::WATER, 1, 100.0, '2026-08-28T10:00:00+00:00'),
                $this->purchase($pilot, self::BACTERIA, 1, 100.0, '2026-08-28T09:59:59+00:00'),
            ],
            [$pilot],
        );

        $this->assertSame(
            [[100.0, 1, 1], [null, 0, 0]],
            [$this->figuresOf($report, self::WATER), $this->figuresOf($report, self::BACTERIA)],
        );
        $this->assertSame(90, PlanetaryPaidPriceCalculator::PAID_PRICE_DAYS);
        $this->assertSame(90, $report['days']);
        $this->assertSame('2026-08-28T10:00:00+00:00', $report['since']);
    }

    public function testSalesAndPurchasesOfATypeOutsideTheSchematicsAreIgnored(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report(
            [
                $this->purchase($pilot, self::WATER, 10, 100.0, '2026-11-20T12:00:00+00:00'),
                $this->purchase($pilot, self::WATER, 10, 900.0, '2026-11-21T12:00:00+00:00', isBuy: false),
                $this->purchase($pilot, self::TRITANIUM, 10, 5.0, '2026-11-21T12:00:00+00:00'),
            ],
            [$pilot],
        );

        $this->assertSame([100.0, 10, 1], $this->figuresOf($report, self::WATER));
        $this->assertSame([self::BACTERIA, self::WATER, self::COOLANT], array_column($report['prices'], 'typeId'));
    }

    public function testThePurchasesOfAnotherUserAreIgnored(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $stranger = $this->character(2112000009, 'Stranger');
        $report = $this->report(
            [
                $this->purchase($pilot, self::WATER, 10, 100.0, '2026-11-20T12:00:00+00:00'),
                $this->purchase($stranger, self::WATER, 10, 900.0, '2026-11-20T12:00:00+00:00'),
            ],
            [$pilot],
        );

        $this->assertSame([100.0, 10, 1], $this->figuresOf($report, self::WATER));
    }

    public function testAnAccountWithoutCharacterGivesUnknownPricesForEveryType(): void
    {
        $report = $this->report([], []);

        $this->assertSame([null, null, null], array_column($report['prices'], 'paidPrice'));
        $this->assertSame([], $report['unsyncedWallets']);
    }

    public function testEachRowCarriesTheNameOfItsType(): void
    {
        $report = $this->report([], []);

        $this->assertSame(['Bacteria', 'Water', 'Coolant'], array_column($report['prices'], 'typeName'));
    }

    public function testACharacterWhoseWalletIsNotReadIsReported(): void
    {
        $synced = $this->character(2112000001, 'Pilot One');
        $unsynced = $this->character(2112000002, 'Pilot Two');
        $report = $this->report([], [$synced, $unsynced], unreadWalletCharacterIds: [2112000002]);

        $this->assertSame(
            [['characterId' => 2112000002, 'characterName' => 'Pilot Two', 'status' => 'never_read']],
            $report['unsyncedWallets'],
        );
    }

    public function testASyncedAccountReportsNoUnsyncedWallet(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report([], [$pilot]);

        $this->assertSame([], $report['unsyncedWallets']);
    }

    /**
     * @param array<string, mixed> $report
     * @return array{float|null, int, int}
     */
    private function figuresOf(array $report, int $typeId): array
    {
        foreach ($report['prices'] as $row) {
            if ($row['typeId'] === $typeId) {
                return [$row['paidPrice'], $row['quantity'], $row['purchaseCount']];
            }
        }

        $this->fail(sprintf('No row for type %d', $typeId));
    }

    /**
     * @param list<CachedWalletTransaction> $transactions
     * @param list<Character> $characters
     * @param list<int> $unreadWalletCharacterIds
     * @return array<string, mixed>
     */
    private function report(array $transactions, array $characters, array $unreadWalletCharacterIds = []): array
    {
        $user = new User();
        foreach ($characters as $character) {
            $user->addCharacter($character);
        }

        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByCharactersSince')->willReturnCallback(
            static function (array $ofCharacters, array $typeIds, \DateTimeImmutable $since) use ($transactions): array {
                return array_values(array_filter(
                    $transactions,
                    static fn (CachedWalletTransaction $transaction): bool => $transaction->isBuy()
                        && in_array($transaction->getCharacter(), $ofCharacters, true)
                        && in_array($transaction->getTypeId(), $typeIds, true)
                        && $transaction->getDate() >= $since,
                ));
            },
        );

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            1 => ['inputs' => [self::WATER => 1_500, self::BACTERIA => 1_500], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]],
            2 => ['inputs' => [self::COOLANT => 3, self::WATER => 40], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 1]],
        ]);

        $names = [self::WATER => 'Water', self::BACTERIA => 'Bacteria', self::COOLANT => 'Coolant', self::ROBOTICS => 'Robotics'];
        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findName')->willReturnCallback(static fn (int $typeId): ?string => $names[$typeId] ?? null);

        $readings = array_map(
            fn (Character $character): CharacterWalletReading => (new CharacterWalletReading())
                ->setCharacter($character)
                ->setReadAt(new \DateTimeImmutable('2026-11-26T09:30:00+00:00'))
                ->setNextReadAt(new \DateTimeImmutable('2026-11-26T10:30:00+00:00')),
            array_values(array_filter(
                $characters,
                static fn (Character $character): bool => !in_array($character->getEveCharacterId(), $unreadWalletCharacterIds, true),
            )),
        );
        $readingRepository = $this->createStub(CharacterWalletReadingRepository::class);
        $readingRepository->method('findByCharacters')->willReturn($readings);

        return (new PlanetaryPaidPriceCalculator(
            $transactionRepository,
            $schematicTypeRepository,
            $typeNameResolver,
            new WalletReadingStateReader($readingRepository),
        ))->calculate($user, new \DateTimeImmutable(self::NOW));
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setEveToken((new EveToken())->setScopes([self::WALLET_SCOPE]));
    }

    private function purchase(Character $character, int $typeId, int $quantity, float $unitPrice, string $date, bool $isBuy = true): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($this->nextTransactionId++)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy($isBuy)
            ->setLocationId(60003760)
            ->setDate(new \DateTimeImmutable($date));
    }
}
