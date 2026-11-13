<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Service\MoonChunk\MoonChunkValue;
use App\Service\MoonChunk\MoonChunkValuer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonChunkValuer::class)]
#[CoversClass(MoonChunkValue::class)]
#[Group('issue-308')]
final class MoonChunkValuerTest extends TestCase
{
    private const int BITUMENS = 45492;
    private const int CHROMITE = 45501;
    private const float CHUNK_VOLUME_M3 = 1_000_000.0;

    private MoonChunkValuer $valuer;

    protected function setUp(): void
    {
        $this->valuer = new MoonChunkValuer();
    }

    public function testTheParticipantCountsAreFourSixAndEight(): void
    {
        self::assertSame([4, 6, 8], MoonChunkValuer::PARTICIPANT_COUNTS);
    }

    public function testAChunkOfTwoOresIsValuedOreByOreAndSplitBetweenFourSixAndEightParticipants(): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 0.5, self::CHROMITE => 0.5],
            self::CHUNK_VOLUME_M3,
            [self::BITUMENS => 100.0, self::CHROMITE => 300.0],
            [self::BITUMENS => 10.0, self::CHROMITE => 10.0],
        );

        self::assertSame(
            [
                'known',
                null,
                20_000_000.0,
                [[4, 5_000_000.0], [6, 3_333_333.33], [8, 2_500_000.0]],
                [[self::BITUMENS, 0.5, 50_000.0, 5_000_000.0], [self::CHROMITE, 0.5, 50_000.0, 15_000_000.0]],
            ],
            $this->figuresOf($value),
        );
    }

    #[Group('issue-853')]
    public function testSharesAddingUpToLessThanOneValueOnlyTheListedOresAndTheRestOfTheChunkIsWorthNothing(): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 0.5, self::CHROMITE => 0.3],
            self::CHUNK_VOLUME_M3,
            [self::BITUMENS => 100.0, self::CHROMITE => 300.0],
            [self::BITUMENS => 10.0, self::CHROMITE => 10.0],
        );

        self::assertSame(['known', 14_000_000.0], [$value->status, $value->totalValue]);
    }

    public function testEachOreUsesItsOwnShareVolumeAndPrice(): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 0.4, self::CHROMITE => 0.6],
            1_850_000.0,
            [self::BITUMENS => 120.0, self::CHROMITE => 250.0],
            [self::BITUMENS => 16.0, self::CHROMITE => 8.0],
        );

        self::assertSame(
            [
                'known',
                null,
                40_237_500.0,
                [[4, 10_059_375.0], [6, 6_706_250.0], [8, 5_029_687.5]],
                [[self::BITUMENS, 0.4, 46_250.0, 5_550_000.0], [self::CHROMITE, 0.6, 138_750.0, 34_687_500.0]],
            ],
            $this->figuresOf($value),
        );
    }

    public function testAnOreKnownAtAPriceOfZeroIsValuedAtZeroAndTheChunkStaysKnown(): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 0.5, self::CHROMITE => 0.5],
            self::CHUNK_VOLUME_M3,
            [self::BITUMENS => 100.0, self::CHROMITE => 0.0],
            [self::BITUMENS => 10.0, self::CHROMITE => 16.0],
        );

        self::assertSame(
            ['known', null, 5_000_000.0, [[self::BITUMENS, 0.5, 50_000.0, 5_000_000.0], [self::CHROMITE, 0.5, 31_250.0, 0.0]]],
            [$value->status, $value->reason, $value->totalValue, $this->lineFigures($value)],
        );
    }

    public function testAChunkWithoutVolumeIsKnownAtZero(): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 1.0],
            0.0,
            [self::BITUMENS => 100.0],
            [self::BITUMENS => 10.0],
        );

        self::assertSame(['known', null, 0.0, [[self::BITUMENS, 1.0, 0.0, 0.0]]], [$value->status, $value->reason, $value->totalValue, $this->lineFigures($value)]);
    }

    public function testAMoonWithoutCompositionHasAnUnknownValueForLackOfScan(): void
    {
        $value = $this->valuer->value([], self::CHUNK_VOLUME_M3, [], []);

        self::assertSame(['unknown', 'no_scan', null, null, null], $this->figuresOf($value));
    }

    public function testAnOreWithoutCompressedEquivalentPriceMakesTheWholeChunkUnknownInsteadOfAPartialSum(): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 0.5, self::CHROMITE => 0.5],
            self::CHUNK_VOLUME_M3,
            [self::BITUMENS => 100.0, self::CHROMITE => null],
            [self::BITUMENS => 10.0, self::CHROMITE => 10.0],
        );

        self::assertSame(['unknown', 'no_price', null, null, null], $this->figuresOf($value));
    }

    public static function unusableUnitVolumes(): iterable
    {
        yield 'absent from the SDE' => [null];
        yield 'zero' => [0.0];
        yield 'negative' => [-5.0];
    }

    #[DataProvider('unusableUnitVolumes')]
    public function testAnOreWithoutUsableSdeVolumeMakesTheWholeChunkUnknown(?float $chromiteUnitVolume): void
    {
        $value = $this->valuer->value(
            [self::BITUMENS => 0.5, self::CHROMITE => 0.5],
            self::CHUNK_VOLUME_M3,
            [self::BITUMENS => 100.0, self::CHROMITE => 300.0],
            [self::BITUMENS => 10.0, self::CHROMITE => $chromiteUnitVolume],
        );

        self::assertSame(['unknown', 'no_volume', null, null, null], $this->figuresOf($value));
    }

    private function figuresOf(MoonChunkValue $value): array
    {
        return [
            $value->status,
            $value->reason,
            $value->totalValue === null ? null : round($value->totalValue, 2),
            $value->perParticipant === null ? null : array_map(static fn (array $part): array => [$part['participants'], round($part['value'], 2)], $value->perParticipant),
            $value->lines === null ? null : array_map(static fn (array $line): array => [$line['typeId'], $line['share'], $line['units'], $line['value']], $value->lines),
        ];
    }

    private function lineFigures(MoonChunkValue $value): array
    {
        return $this->figuresOf($value)[4] ?? [];
    }
}
