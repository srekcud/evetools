<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\StockpileProductNotPlannableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[CoversClass(StockpileProductNotPlannableException::class)]
#[Group('issue-258')]
final class StockpileProductNotPlannableExceptionTest extends TestCase
{
    private const int TYPE_WITHOUT_BLUEPRINT = 99999;
    private const int WIDGET = 3001;
    private const string CYCLE_FAILURE = 'Product 3002 is needed to build itself: the production graph has a cycle.';

    public function testTypeWithoutBlueprintNorReactionIsNotPlannableForNoBlueprintWithAStableMessage(): void
    {
        $exception = StockpileProductNotPlannableException::noBlueprint(self::TYPE_WITHOUT_BLUEPRINT);

        self::assertSame(
            ['no_blueprint', 'Type 99999 has no blueprint nor reaction', null],
            [$exception->getReason(), $exception->getMessage(), $exception->getPrevious()],
        );
    }

    public function testTypeWhosePlanFailsIsNotPlannableForPlanFailedWithAStableMessageAndKeepsTheFailureAsPrevious(): void
    {
        $planFailure = new \DomainException(self::CYCLE_FAILURE);

        $exception = StockpileProductNotPlannableException::planFailed(self::WIDGET, $planFailure);

        self::assertSame(
            ['plan_failed', 'Type 3001 cannot be planned', $planFailure],
            [$exception->getReason(), $exception->getMessage(), $exception->getPrevious()],
        );
    }

    public function testPublicMessageOfAFailedPlanNeverCarriesTheMessageOfThePlanFailure(): void
    {
        $exception = StockpileProductNotPlannableException::planFailed(self::WIDGET, new \DomainException(self::CYCLE_FAILURE));

        self::assertStringNotContainsString('cycle', $exception->getMessage());
        self::assertStringNotContainsString('3002', $exception->getMessage());
        self::assertStringContainsString('3001', $exception->getMessage());
    }

    public function testNotPlannableProductIsAnsweredWithTheHttpStatus422(): void
    {
        $statusCodes = array_map(
            static fn (\ReflectionAttribute $attribute): int => $attribute->newInstance()->statusCode,
            (new \ReflectionClass(StockpileProductNotPlannableException::class))->getAttributes(WithHttpStatus::class),
        );

        self::assertSame([422], $statusCodes);
    }
}
