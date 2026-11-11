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
final class UndercutAssessorTest extends TestCase
{
    /**
     * @return iterable<string, array{float, list<float>, OrderPositionState, int, float, float|null}>
     */
    public static function knownPositions(): iterable
    {
        yield 'third of three' => [1000.0, [900.0, 950.0, 1000.0], OrderPositionState::Undercut, 3, 900.0, 800.0];
        yield 'cheapest of two' => [1000.0, [1000.0, 1100.0], OrderPositionState::First, 1, 1000.0, null];
        yield 'tie is not undercut' => [1000.0, [1000.0, 1000.0], OrderPositionState::First, 1, 1000.0, null];
        yield 'order missing from an older book' => [1000.0, [950.0], OrderPositionState::Undercut, 2, 950.0, 850.0];
        yield 'book only above my price' => [1000.0, [1200.0], OrderPositionState::First, 1, 1000.0, null];
        yield 'cheap item' => [200.0, [150.0], OrderPositionState::Undercut, 2, 150.0, 50.0];
        yield 'suggested price would reach zero' => [150.0, [100.0], OrderPositionState::Undercut, 2, 100.0, null];
        yield 'suggested price would be negative' => [150.0, [60.0], OrderPositionState::Undercut, 2, 60.0, null];
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

    public function testSuggestedPriceIsOneStepOfOneHundredIskUnderTheBestPrice(): void
    {
        self::assertSame(100.0, UndercutAssessor::UNDERCUT_STEP);
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
