<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input;

use App\ApiResource\Input\ShoppingList\ParseListInput;
use App\ApiResource\Input\ShoppingList\ShareListInput;
use App\ApiResource\ShoppingList\ParseResultResource;
use App\Constant\EveConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('issue-1322')]
final class ShoppingListTransportCostDefaultTest extends TestCase
{
    private const float COURIER_RATE_PER_M3 = 800.0;

    #[Test]
    public function theCourierRateReferenceIsEightHundredIskPerCubicMeter(): void
    {
        self::assertSame(self::COURIER_RATE_PER_M3, EveConstants::DEFAULT_EXPORT_COST_PER_M3);
    }

    #[Test]
    public function aParseRequestWithoutTransportCostUsesTheCourierRate(): void
    {
        self::assertSame(self::COURIER_RATE_PER_M3, (new ParseListInput())->transportCost);
    }

    #[Test]
    public function aShareRequestWithoutTransportCostUsesTheCourierRate(): void
    {
        self::assertSame(self::COURIER_RATE_PER_M3, (new ShareListInput())->transportCostPerM3);
    }

    #[Test]
    public function aParseResultWithoutTransportCostReportsTheCourierRate(): void
    {
        self::assertSame(self::COURIER_RATE_PER_M3, (new ParseResultResource())->transportCostPerM3);
    }
}
