<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\OrderVolumeDecreases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderVolumeDecreases::class)]
final class OrderVolumeDecreasesTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private OrderVolumeDecreases $decreases;

    protected function setUp(): void
    {
        $this->decreases = new OrderVolumeDecreases();
    }

    public function testDecreaseOfAnOrderPresentInBothReadsIsTheTradedVolumeOfItsType(): void
    {
        $previous = [1 => $this->order(self::TRITANIUM, 1000)];
        $current = [1 => $this->order(self::TRITANIUM, 700)];

        self::assertSame([self::TRITANIUM => 300], $this->decreases->between($previous, $current));
    }

    public function testUnchangedOrderProducesNoEntryForItsType(): void
    {
        $previous = [1 => $this->order(self::TRITANIUM, 1000)];
        $current = [1 => $this->order(self::TRITANIUM, 1000)];

        self::assertSame([], $this->decreases->between($previous, $current));
    }

    public function testOrderMissingFromTheCurrentReadAndNewOrderAreNotCounted(): void
    {
        $previous = [1 => $this->order(self::TRITANIUM, 1000)];
        $current = [2 => $this->order(self::TRITANIUM, 500)];

        self::assertSame([], $this->decreases->between($previous, $current));
    }

    public function testDecreasesOfSeveralOrdersAreSummedPerType(): void
    {
        $previous = [
            1 => $this->order(self::TRITANIUM, 1000),
            2 => $this->order(self::TRITANIUM, 50),
            3 => $this->order(self::PYERITE, 10),
        ];
        $current = [
            1 => $this->order(self::TRITANIUM, 900),
            2 => $this->order(self::TRITANIUM, 20),
            3 => $this->order(self::PYERITE, 4),
        ];

        self::assertSame(
            [self::TRITANIUM => 130, self::PYERITE => 6],
            $this->decreases->between($previous, $current),
        );
    }

    public function testIncreaseOfAnOrderIsIgnored(): void
    {
        $previous = [1 => $this->order(self::TRITANIUM, 700)];
        $current = [1 => $this->order(self::TRITANIUM, 900)];

        self::assertSame([], $this->decreases->between($previous, $current));
    }

    public function testIncreaseOfAnOrderDoesNotOffsetTheDecreaseOfAnotherOrderOfTheSameType(): void
    {
        $previous = [
            4 => $this->order(self::MEXALLON, 500),
            5 => $this->order(self::MEXALLON, 200),
        ];
        $current = [
            4 => $this->order(self::MEXALLON, 450),
            5 => $this->order(self::MEXALLON, 260),
        ];

        self::assertSame([self::MEXALLON => 50], $this->decreases->between($previous, $current));
    }

    public function testOrderWhoseTypeChangedBetweenTheTwoReadsIsNotCounted(): void
    {
        $previous = [1 => $this->order(self::TRITANIUM, 1000)];
        $current = [1 => $this->order(self::PYERITE, 600)];

        self::assertSame([], $this->decreases->between($previous, $current));
    }

    public function testOnlyTypesWithADecreaseAppearWhenOtherTypesAreUnchanged(): void
    {
        $previous = [
            1 => $this->order(self::TRITANIUM, 1000),
            2 => $this->order(self::PYERITE, 80),
            3 => $this->order(self::MEXALLON, 25),
        ];
        $current = [
            1 => $this->order(self::TRITANIUM, 1000),
            2 => $this->order(self::PYERITE, 75),
            3 => $this->order(self::MEXALLON, 25),
        ];

        self::assertSame([self::PYERITE => 5], $this->decreases->between($previous, $current));
    }

    public function testEmptyPreviousReadProducesNoEntry(): void
    {
        $current = [1 => $this->order(self::TRITANIUM, 1000)];

        self::assertSame([], $this->decreases->between([], $current));
    }

    /** @return array{typeId: int, volumeRemain: int} */
    private function order(int $typeId, int $volumeRemain): array
    {
        return ['typeId' => $typeId, 'volumeRemain' => $volumeRemain];
    }
}
