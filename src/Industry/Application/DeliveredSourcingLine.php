<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
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
}
