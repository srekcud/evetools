<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\JitaPurchase;
use App\Industry\Application\MarketPrices;

final readonly class ActionAssessor
{
    private const string CODE_SOURCE_MATERIAL = 'source_material';
    private const array CODES_WITH_QUANTITY = ['review_contributions', 'source_material', 'launch_job', 'record_sale'];
    private const int ISK_DECIMALS = 2;

    public function __construct(private MarketPrices $marketPrices)
    {
    }

    public function assess(CockpitNextAction $action): ActionAssessment
    {
        $impactIsk = $this->impactIskOf($action);

        return new ActionAssessment($this->severityOf($action, $impactIsk), $impactIsk);
    }

    private function severityOf(CockpitNextAction $action, ?float $impactIsk): ActionSeverity
    {
        if ($action->blocked) {
            return ActionSeverity::Warning;
        }

        return $this->hasUnknownData($action, $impactIsk) ? ActionSeverity::Unknown : ActionSeverity::Info;
    }

    private function hasUnknownData(CockpitNextAction $action, ?float $impactIsk): bool
    {
        if (\in_array($action->code, self::CODES_WITH_QUANTITY, true) && null === $action->quantity) {
            return true;
        }

        return self::CODE_SOURCE_MATERIAL === $action->code && null === $impactIsk;
    }

    private function impactIskOf(CockpitNextAction $action): ?float
    {
        if (self::CODE_SOURCE_MATERIAL !== $action->code || null === $action->typeId || null === $action->quantity) {
            return null;
        }

        $unitPrices = $this->marketPrices->unitPricesFor([$action->typeId => (float) $action->quantity]);
        $cost = JitaPurchase::priceCostOf($action->typeId, $action->quantity, $unitPrices[$action->typeId] ?? null);

        return $cost->isKnown() ? round($cost->amount()->amount, self::ISK_DECIMALS) : null;
    }
}
