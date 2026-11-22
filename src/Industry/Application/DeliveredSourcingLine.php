<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Quantity;

final readonly class DeliveredSourcingLine
{
    /**
     * @param list<string>                                $consumerStructureIds
     * @param array<string, DeliveredSourcingOption>      $options
     */
    public function __construct(
        public int $typeId,
        public ?WorkType $workType,
        public ?ActivityKind $activity,
        public Quantity $quantity,
        public Quantity $universeDemand,
        public int $runs,
        public Quantity $surplus,
        public array $consumerStructureIds,
        public array $options,
        public ?SourcingChoice $recommended,
        public ?SourcingChoice $chosen,
        public bool $bought,
        public ?Quantity $universeProduced,
        public ?float $volumePerUnit,
    ) {
    }

    public function option(SourcingChoice $kind): ?DeliveredSourcingOption
    {
        return $this->options[$kind->value] ?? null;
    }

    public function perUnitOf(SourcingChoice $kind): ?Cost
    {
        $option = $this->option($kind);

        return null === $option ? null : $option->total->times(1 / $this->universeDemand->value);
    }

    public function totalOf(SourcingChoice $kind): ?Cost
    {
        $perUnit = $this->perUnitOf($kind);

        return $perUnit?->times($this->quantity->value);
    }

    public function chosenTotal(): ?Cost
    {
        return null === $this->chosen ? null : $this->totalOf($this->chosen);
    }

    public function perUnitIfSurplusReused(): ?Cost
    {
        $produced = $this->producedBeyondDemand();
        $build = $this->option(SourcingChoice::Build);
        if (null === $produced || null === $build) {
            return null;
        }

        return $build->total->times(1 / $produced);
    }

    public function savingIfSurplusReused(): ?Isk
    {
        $produced = $this->producedBeyondDemand();
        $build = $this->option(SourcingChoice::Build);
        $purchase = null === $this->recommended || SourcingChoice::Build === $this->recommended ? null : $this->option($this->recommended);
        if (null === $produced || null === $build || null === $purchase || !$build->total->isKnown() || !$purchase->total->isKnown()) {
            return null;
        }

        $reusedBuildTotal = $build->total->amount()->amount * $this->universeDemand->value / $produced;
        if ($reusedBuildTotal >= $purchase->total->amount()->amount) {
            return null;
        }

        return new Isk($purchase->total->amount()->amount - $reusedBuildTotal);
    }

    public function choiceChangesWithoutImport(): bool
    {
        $build = $this->option(SourcingChoice::Build);
        $jita = $this->option(SourcingChoice::Jita);
        if (null === $build || null === $jita) {
            return false;
        }

        return self::verdictOf($build->total, $jita->total) !== self::verdictOf($build->total, $jita->price);
    }

    private function producedBeyondDemand(): ?int
    {
        $produced = $this->universeProduced?->value;

        return null !== $produced && $produced > $this->universeDemand->value ? $produced : null;
    }

    private static function verdictOf(Cost $buildCost, Cost $buyCost): BuyOrBuildChoice
    {
        if (!$buildCost->isKnown() || !$buyCost->isKnown()) {
            return BuyOrBuildChoice::Unknown;
        }

        return $buildCost->amount()->isAtMost($buyCost->amount()) ? BuyOrBuildChoice::Build : BuyOrBuildChoice::Buy;
    }
}
