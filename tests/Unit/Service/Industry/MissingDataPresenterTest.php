<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Service\Industry\MissingDataPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingDataPresenter::class)]
final class MissingDataPresenterTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int HAIL_L_T1_BLUEPRINT = 2_000_000_001;

    public function testKnownCostHasNoMissingData(): void
    {
        $this->assertSame([], MissingDataPresenter::of(Cost::known(new Isk(1234.5))));
    }

    public function testMissingDataKeepsTheOrderOfAppearanceWithANullTypeIdForTheCostIndex(): void
    {
        $cost = Cost::unknown(MissingData::marketPrice(self::TRITANIUM), MissingData::costIndex(), MissingData::adjustedPrice(self::PYERITE));

        $this->assertSame(
            [
                ['reason' => 'market_price', 'typeId' => self::TRITANIUM],
                ['reason' => 'cost_index', 'typeId' => null],
                ['reason' => 'adjusted_price', 'typeId' => self::PYERITE],
            ],
            MissingDataPresenter::of($cost),
        );
    }

    public function testMissingDataOfASumIsListedOnce(): void
    {
        $cost = Cost::unknown(MissingData::marketPrice(self::TRITANIUM))
            ->plus(Cost::known(new Isk(100.0)))
            ->plus(Cost::unknown(MissingData::marketPrice(self::TRITANIUM), MissingData::inventionProbability(self::HAIL_L_T1_BLUEPRINT)));

        $this->assertSame(
            [
                ['reason' => 'market_price', 'typeId' => self::TRITANIUM],
                ['reason' => 'invention_probability', 'typeId' => self::HAIL_L_T1_BLUEPRINT],
            ],
            MissingDataPresenter::of($cost),
        );
    }
}
