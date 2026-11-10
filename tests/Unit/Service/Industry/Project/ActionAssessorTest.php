<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\MarketPrices;
use App\Industry\Application\WeightedPrice;
use App\Industry\Domain\Isk;
use App\Industry\Domain\OrderBookCoverage;
use App\Service\Industry\Project\ActionAssessor;
use App\Service\Industry\Project\ActionSeverity;
use App\Service\Industry\Project\CockpitNextAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionAssessor::class)]
#[CoversClass(ActionSeverity::class)]
#[Group('issue-574')]
final class ActionAssessorTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int RAVEN_TYPE_ID = 638;

    public function testBlockedMaterialIsAWarningWorthTheQuantityAtTheJitaPrice(): void
    {
        $assessment = $this->assessorPricing([self::TRITANIUM_TYPE_ID => 5.5])->assess(
            $this->action('source_material', blocked: true, typeId: self::TRITANIUM_TYPE_ID, quantity: 27_500),
        );

        self::assertSame([ActionSeverity::Warning, 151_250.0], [$assessment->severity, $assessment->impactIsk]);
    }

    public function testMaterialToSourceBeforeTheProjectStartsIsInfoWithItsImpact(): void
    {
        $assessment = $this->assessorPricing([self::TRITANIUM_TYPE_ID => 5.5])->assess(
            $this->action('source_material', blocked: false, typeId: self::TRITANIUM_TYPE_ID, quantity: 1_000),
        );

        self::assertSame([ActionSeverity::Info, 5_500.0], [$assessment->severity, $assessment->impactIsk]);
    }

    public function testMaterialWithoutJitaPriceHasAnUnknownImpactInsteadOfZeroAndIsUnknownWhenNotBlocked(): void
    {
        $assessor = $this->assessorPricing([]);

        $notBlocked = $assessor->assess($this->action('source_material', blocked: false, typeId: self::TRITANIUM_TYPE_ID, quantity: 1_000));
        $blocked = $assessor->assess($this->action('source_material', blocked: true, typeId: self::TRITANIUM_TYPE_ID, quantity: 1_000));

        self::assertSame(
            [[ActionSeverity::Unknown, null], [ActionSeverity::Warning, null]],
            [[$notBlocked->severity, $notBlocked->impactIsk], [$blocked->severity, $blocked->impactIsk]],
        );
    }

    public function testImpactIsRoundedToTwoDecimals(): void
    {
        $assessment = $this->assessorPricing([self::TRITANIUM_TYPE_ID => 5.555])->assess(
            $this->action('source_material', blocked: true, typeId: self::TRITANIUM_TYPE_ID, quantity: 3),
        );

        self::assertSame(16.67, $assessment->impactIsk);
    }

    public function testMarketIsAskedTheRemainingQuantityOfTheType(): void
    {
        $marketPrices = $this->createMock(MarketPrices::class);
        $marketPrices->expects(self::once())->method('unitPricesFor')->with([self::TRITANIUM_TYPE_ID => 27_500.0])->willReturn([]);

        (new ActionAssessor($marketPrices))->assess($this->action('source_material', blocked: true, typeId: self::TRITANIUM_TYPE_ID, quantity: 27_500));
    }

    public function testJobToLaunchHasNoImpactAndIsInfoWhenItsRunsAreKnown(): void
    {
        $assessment = $this->assessorPricing([self::RAVEN_TYPE_ID => 900_000_000.0])->assess(
            $this->action('launch_job', blocked: false, typeId: self::RAVEN_TYPE_ID, quantity: 4),
        );

        self::assertSame([ActionSeverity::Info, null], [$assessment->severity, $assessment->impactIsk]);
    }

    public function testJobToLaunchWithUnknownRunsIsUnknown(): void
    {
        $assessment = $this->assessorPricing([])->assess($this->action('launch_job', blocked: false, typeId: self::RAVEN_TYPE_ID, quantity: null));

        self::assertSame([ActionSeverity::Unknown, null], [$assessment->severity, $assessment->impactIsk]);
    }

    public function testActionsWithoutQuantityAreInfoAndNeverAskTheMarket(): void
    {
        $marketPrices = $this->createMock(MarketPrices::class);
        $marketPrices->expects(self::never())->method('unitPricesFor');
        $assessor = new ActionAssessor($marketPrices);

        $codes = ['start_project', 'start_selling', 'complete_project'];
        $assessments = array_map(fn (string $code) => $assessor->assess($this->action($code)), $codes);

        self::assertSame(
            [[ActionSeverity::Info, null], [ActionSeverity::Info, null], [ActionSeverity::Info, null]],
            array_map(static fn ($assessment): array => [$assessment->severity, $assessment->impactIsk], $assessments),
        );
    }

    public function testSaleToRecordWithoutKnownQuantityIsUnknown(): void
    {
        $assessor = $this->assessorPricing([]);

        $known = $assessor->assess($this->action('record_sale', quantity: 3));
        $unknown = $assessor->assess($this->action('record_sale', quantity: null));

        self::assertSame([ActionSeverity::Info, ActionSeverity::Unknown], [$known->severity, $unknown->severity]);
    }

    public function testSeverityIsExposedUnderItsApiValue(): void
    {
        self::assertSame(['warning', 'info', 'unknown'], [ActionSeverity::Warning->value, ActionSeverity::Info->value, ActionSeverity::Unknown->value]);
    }

    /**
     * @param array<int, float> $unitPriceByTypeId
     */
    private function assessorPricing(array $unitPriceByTypeId): ActionAssessor
    {
        $marketPrices = $this->createStub(MarketPrices::class);
        $marketPrices->method('unitPricesFor')->willReturn(array_map(
            static fn (float $unitPrice): WeightedPrice => new WeightedPrice(new Isk($unitPrice), new OrderBookCoverage(1.0)),
            $unitPriceByTypeId,
        ));

        return new ActionAssessor($marketPrices);
    }

    private function action(string $code, bool $blocked = false, ?int $typeId = null, ?int $quantity = null): CockpitNextAction
    {
        return new CockpitNextAction($code, $blocked, null, null, null, $typeId, null, $quantity);
    }
}
