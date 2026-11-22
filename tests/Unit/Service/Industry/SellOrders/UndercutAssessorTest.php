<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellOrders;

use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;
use App\Dto\OrderPosition;
use App\Service\Industry\SellOrders\UndercutAssessor;
use App\Service\Industry\SellOrders\UndercutSince;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UndercutAssessor::class)]
#[CoversClass(OrderPosition::class)]
#[CoversClass(UndercutSince::class)]
#[Group('issue-350')]
#[Group('issue-1356')]
final class UndercutAssessorTest extends TestCase
{
    /**
     * @return iterable<string, array{float, list<float>, OrderPositionState, int, float, float|null}>
     */
    public static function knownPositions(): iterable
    {
        yield 'third of three' => [1000.0, [900.0, 950.0, 1000.0], OrderPositionState::Undercut, 3, 900.0, 899.9];
        yield 'cheapest of two' => [1000.0, [1000.0, 1100.0], OrderPositionState::First, 1, 1000.0, null];
        yield 'tie is not undercut' => [1000.0, [1000.0, 1000.0], OrderPositionState::First, 1, 1000.0, null];
        yield 'order missing from an older book' => [1000.0, [950.0], OrderPositionState::Undercut, 2, 950.0, 949.9];
        yield 'book only above my price' => [1000.0, [1200.0], OrderPositionState::First, 1, 1000.0, null];
        yield 'cheap item' => [393.0, [390.0], OrderPositionState::Undercut, 2, 390.0, 389.9];
        yield 'best price on a power of ten' => [482.0, [400.0], OrderPositionState::Undercut, 2, 400.0, 399.9];
        yield 'expensive item' => [1300000.0, [1234000.0], OrderPositionState::Undercut, 2, 1234000.0, 1233000.0];
        yield 'best price off the tick grid' => [1300.0, [1234.56], OrderPositionState::Undercut, 2, 1234.56, 1234.0];
        yield 'price under ten ISK keeps cents' => [6.0, [5.5], OrderPositionState::Undercut, 2, 5.5, 5.49];
        yield 'best price is the smallest price' => [1.0, [0.01], OrderPositionState::Undercut, 2, 0.01, null];
    }

    /**
     * @param list<float> $bookPrices
     */
    #[DataProvider('knownPositions')]
    public function testPositionOfAnOrderAgainstTheSellBook(float $myPrice, array $bookPrices, OrderPositionState $state, int $rank, float $bestPrice, ?float $suggestedPrice): void
    {
        $book = array_map(static fn (float $price): array => ['price' => $price, 'volume' => 1], $bookPrices);

        $position = (new UndercutAssessor())->assess($myPrice, $book);

        self::assertSame(
            [$state, $rank, $bestPrice, null, $suggestedPrice],
            [$position->state, $position->rank, $position->bestPrice, $position->unknownReason, $position->suggestedPrice],
        );
    }

    public function testEmptyBookIsUnknownWithItsCauseAndNeverFirst(): void
    {
        $position = (new UndercutAssessor())->assess(1000.0, []);

        self::assertSame(
            [OrderPositionState::Unknown, null, null, OrderPositionUnknownReason::NoMarketData, null],
            [$position->state, $position->rank, $position->bestPrice, $position->unknownReason, $position->suggestedPrice],
        );
    }

    public function testMarketNotFollowedIsUnknownWithItsCause(): void
    {
        $position = (new UndercutAssessor())->assess(1000.0, null);

        self::assertSame(
            [OrderPositionState::Unknown, null, null, OrderPositionUnknownReason::MarketNotFollowed, null],
            [$position->state, $position->rank, $position->bestPrice, $position->unknownReason, $position->suggestedPrice],
        );
    }

    #[DataProvider('sinceTransitions')]
    public function testUndercutSinceFollowsTheSuccessiveSyncs(?string $previous, OrderPositionState $state, ?string $expected): void
    {
        $now = new \DateTimeImmutable('2026-11-10 12:15:00');

        $since = UndercutSince::resolve($previous === null ? null : new \DateTimeImmutable($previous), $state, $now);

        self::assertSame($expected, $since?->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string|null, OrderPositionState, string|null}>
     */
    public static function sinceTransitions(): iterable
    {
        yield 'newly undercut' => [null, OrderPositionState::Undercut, '2026-11-10 12:15:00'];
        yield 'still undercut keeps the first sync' => ['2026-11-10 12:00:00', OrderPositionState::Undercut, '2026-11-10 12:00:00'];
        yield 'back to first' => ['2026-11-10 12:00:00', OrderPositionState::First, null];
        yield 'position became unknown' => ['2026-11-10 12:00:00', OrderPositionState::Unknown, null];
        yield 'first without history' => [null, OrderPositionState::First, null];
    }
}
