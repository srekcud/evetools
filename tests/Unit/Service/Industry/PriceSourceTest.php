<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\PriceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceSource::class)]
#[Group('issue-538')]
final class PriceSourceTest extends TestCase
{
    public function testJitaSellPercentileIsLabelledWithThePercentileInPercentAndNoCoverage(): void
    {
        $label = $this->sortedByKey(PriceSource::jitaSellPercentile(5.0)->toArray());

        $this->assertSame(
            ['coveragePercent' => null, 'market' => 'jita', 'method' => 'percentile', 'percentile' => 5.0, 'side' => 'sell'],
            $label,
        );
    }

    public function testJitaSellPercentileCarriesTheGivenPercentileNotAFixedOne(): void
    {
        $label = PriceSource::jitaSellPercentile(2.5)->toArray();

        $this->assertArrayHasKey('percentile', $label);
        $this->assertSame(2.5, $label['percentile']);
    }

    public function testOnlyThePercentileMethodAddsAPercentileKeyToTheArray(): void
    {
        $this->assertSame(
            ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
            PriceSource::jitaSellWeighted(null)->toArray(),
        );
        $this->assertSame(
            ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 66.7],
            PriceSource::jitaSellWeighted(66.7)->toArray(),
        );
        $this->assertSame(
            ['market' => 'structure', 'side' => 'sell', 'method' => 'lowest', 'coveragePercent' => null],
            PriceSource::structureSellLowest()->toArray(),
        );
        $this->assertSame(
            ['market' => 'structure', 'side' => 'buy', 'method' => 'lowest', 'coveragePercent' => null],
            PriceSource::structureBuyLowest()->toArray(),
        );
        $this->assertSame(
            ['market' => 'contracts', 'side' => 'sell', 'method' => 'lowest', 'coveragePercent' => null],
            PriceSource::contractsSellLowest()->toArray(),
        );
        $this->assertSame(
            ['market' => 'contracts', 'side' => 'sell', 'method' => 'median', 'coveragePercent' => null],
            PriceSource::contractsSellMedian()->toArray(),
        );
    }

    private function sortedByKey(array $label): array
    {
        ksort($label);

        return $label;
    }
}
