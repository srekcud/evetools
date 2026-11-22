<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;

final class SourcingValuation
{
    private const array CHOICE_PRIORITY = [SourcingChoice::Build, SourcingChoice::Jita, SourcingChoice::Structure];
    private const array BUY_PRIORITY = [SourcingChoice::Jita, SourcingChoice::Structure];

    /** @var array<int, SourcingDecision> */
    private array $decisions = [];

    /** @var array<string, Cost> */
    private array $unitCosts = [];

    /**
     * @param array<int, WeightedPrice> $jitaPrices
     * @param array<int, Isk>           $structurePrices
     * @param array<int, int>           $structureSellVolumes
     * @param array<int, ?float>        $volumes
     * @param array<int, bool>          $buys
     * @param array<int, SourcingSide>  $markets
     */
    public function __construct(
        private readonly SourcingUniverse $universe,
        private readonly BlueprintCatalog $blueprints,
        private readonly JobInstallationCosts $installationCosts,
        private readonly array $jitaPrices,
        private readonly array $structurePrices,
        private readonly array $structureSellVolumes,
        private readonly array $volumes,
        private readonly ?CourierHub $hub,
        private readonly ?int $marketStructureId,
        private readonly ?int $marketSolarSystemId,
        private readonly array $buys,
        private readonly array $markets,
    ) {
    }

    public function decisionOf(int $typeId): SourcingDecision
    {
        if (isset($this->decisions[$typeId])) {
            return $this->decisions[$typeId];
        }

        $options = $this->optionsOf($typeId);
        $recommended = self::recommendedOf($options);
        $isBuilt = $this->universe->isBuilt($typeId);
        $forcedBuy = $isBuilt && true === ($this->buys[$typeId] ?? null);
        $forcedBuild = $isBuilt && false === ($this->buys[$typeId] ?? null);

        if ($forcedBuild || ($isBuilt && !$forcedBuy && (null === $recommended || SourcingChoice::Build === $recommended))) {
            $decision = new SourcingDecision($options, $recommended, SourcingChoice::Build, false);
        } else {
            $decision = new SourcingDecision($options, $recommended, $this->buySideOf($typeId, $options), true);
        }

        return $this->decisions[$typeId] = $decision;
    }

    /**
     * @param array<string, DeliveredSourcingOption> $options
     */
    public function buySideOf(int $typeId, array $options): ?SourcingChoice
    {
        $explicit = match ($this->markets[$typeId] ?? null) {
            SourcingSide::Jita => SourcingChoice::Jita,
            SourcingSide::Structure => SourcingChoice::Structure,
            default => null,
        };
        if (null !== $explicit && isset($options[$explicit->value])) {
            return $explicit;
        }

        return self::cheapestOf($options, self::BUY_PRIORITY);
    }

    /**
     * @param array<string, DeliveredSourcingOption> $options
     */
    public static function recommendedOf(array $options): ?SourcingChoice
    {
        return self::cheapestOf($options, self::CHOICE_PRIORITY);
    }

    /**
     * @param array<string, DeliveredSourcingOption> $options
     */
    public static function unknownCostOf(int $typeId, array $options): Cost
    {
        $missingData = [];
        foreach ($options as $option) {
            array_push($missingData, ...$option->total->missingData);
        }

        return Cost::unknown(...([] === $missingData ? [MissingData::marketPrice($typeId)] : $missingData));
    }

    public function volumeOf(int $typeId): ?float
    {
        return $this->volumes[$typeId] ?? null;
    }

    /**
     * @param array<string, DeliveredSourcingOption> $options
     * @param list<SourcingChoice>                   $priority
     */
    private static function cheapestOf(array $options, array $priority): ?SourcingChoice
    {
        $best = null;
        foreach ($priority as $choice) {
            $option = $options[$choice->value] ?? null;
            if (null === $option || !$option->eligible || !$option->total->isKnown()) {
                continue;
            }
            if (null === $best || !$options[$best->value]->total->amount()->isAtMost($option->total->amount())) {
                $best = $choice;
            }
        }

        return $best;
    }

    /**
     * @return array<string, DeliveredSourcingOption>
     */
    private function optionsOf(int $typeId): array
    {
        $demand = $this->universe->demandOf($typeId);
        $options = [];
        if ($this->universe->isBuilt($typeId)) {
            $options[SourcingChoice::Build->value] = $this->buildOptionOf($typeId);
        }
        $options[SourcingChoice::Jita->value] = $this->jitaOptionOf($typeId, $demand);
        if (null !== $this->marketStructureId) {
            $options[SourcingChoice::Structure->value] = $this->structureOptionOf($typeId, $demand);
        }

        return $options;
    }

    private function jitaOptionOf(int $typeId, int $demand): DeliveredSourcingOption
    {
        $price = JitaPurchase::priceCostOf($typeId, $demand, $this->jitaPrices[$typeId] ?? null);
        $transport = $this->transportOver($typeId, fn (int $quantity, ?ProductionStructure $station): Cost => JitaPurchase::importCostOf($typeId, $quantity, $this->volumeOf($typeId), $station?->solarSystemId, $this->hub));
        $total = $price->plus($transport);

        return new DeliveredSourcingOption(SourcingChoice::Jita, $total, $price, Cost::known(new Isk(0.0)), $transport, $total->isKnown());
    }

    private function structureOptionOf(int $typeId, int $demand): DeliveredSourcingOption
    {
        $unitPrice = $this->structurePrices[$typeId] ?? null;
        $price = null === $unitPrice ? Cost::unknown(MissingData::structurePrice($typeId)) : Cost::known(new Isk($demand * $unitPrice->amount));
        $transport = $this->transportOver($typeId, fn (int $quantity, ?ProductionStructure $station): Cost => Delivery::bridgeCostOf($typeId, $quantity, $this->volumeOf($typeId), $this->marketSolarSystemId, $station?->solarSystemId, $this->hub));
        $total = $price->plus($transport);
        $available = $this->structureSellVolumes[$typeId] ?? null;

        return new DeliveredSourcingOption(
            SourcingChoice::Structure,
            $total,
            $price,
            Cost::known(new Isk(0.0)),
            $transport,
            $total->isKnown() && null !== $available && $available >= $demand,
            $available,
            (string) $this->marketStructureId,
        );
    }

    private function buildOptionOf(int $typeId): DeliveredSourcingOption
    {
        $builder = $this->universe->builderByProduct[$typeId] ?? null;
        $inputs = Cost::known(new Isk(0.0));
        $installation = Cost::known(new Isk(0.0));
        foreach ($this->universe->jobsByProduct[$typeId] as $job) {
            foreach ($job->materials as $materialTypeId => $quantity) {
                $inputs = $inputs->plus($this->unitCostAt($materialTypeId, $builder)->times($quantity->value));
            }
            $recipe = $this->blueprints->recipeFor($job->productTypeId)
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
            $installation = $installation->plus($this->installationCosts->installationOf($recipe, $job->runs, $builder));
        }
        $transport = $this->transportOver($typeId, fn (int $quantity, ?ProductionStructure $station): Cost => $this->moveCostOf($typeId, $quantity, $builder, $station));
        $total = $inputs->plus($installation)->plus($transport);

        return new DeliveredSourcingOption(SourcingChoice::Build, $total, $inputs, $installation, $transport, $total->isKnown(), null, $builder?->id);
    }

    /**
     * @param \Closure(int, ?ProductionStructure): Cost $costAt
     */
    private function transportOver(int $typeId, \Closure $costAt): Cost
    {
        $transport = Cost::known(new Isk(0.0));
        foreach ($this->universe->consumption[$typeId] ?? [] as $stationKey => $quantity) {
            $transport = $transport->plus($costAt($quantity, $this->universe->stations[$stationKey]));
        }

        return $transport;
    }

    private function moveCostOf(int $typeId, int $quantity, ?ProductionStructure $from, ?ProductionStructure $to): Cost
    {
        if (null !== $from && $from->id === $to?->id) {
            return Cost::known(new Isk(0.0));
        }

        return Delivery::bridgeCostOf($typeId, $quantity, $this->volumeOf($typeId), $from?->solarSystemId, $to?->solarSystemId, $this->hub);
    }

    private function unitCostAt(int $typeId, ?ProductionStructure $destination): Cost
    {
        $key = $typeId.'|'.($destination->id ?? SourcingUniverse::UNKNOWN_STATION);
        if (isset($this->unitCosts[$key])) {
            return $this->unitCosts[$key];
        }

        $decision = $this->decisionOf($typeId);

        return $this->unitCosts[$key] = null === $decision->effective
            ? self::unknownCostOf($typeId, $decision->options)
            : $this->unitCostOfChoice($typeId, $decision, $decision->effective, $destination);
    }

    private function unitCostOfChoice(int $typeId, SourcingDecision $decision, SourcingChoice $choice, ?ProductionStructure $destination): Cost
    {
        $option = $decision->options[$choice->value];

        return match ($choice) {
            SourcingChoice::Jita => JitaPurchase::priceCostOf($typeId, 1, $this->jitaPrices[$typeId] ?? null)
                ->plus(JitaPurchase::importCostOf($typeId, 1, $this->volumeOf($typeId), $destination?->solarSystemId, $this->hub)),
            SourcingChoice::Structure => $this->structureUnitCostOf($typeId)
                ->plus(Delivery::bridgeCostOf($typeId, 1, $this->volumeOf($typeId), $this->marketSolarSystemId, $destination?->solarSystemId, $this->hub)),
            SourcingChoice::Build => $option->price->plus($option->installation)->times(1 / $this->universe->demandOf($typeId))
                ->plus($this->moveCostOf($typeId, 1, $this->universe->builderByProduct[$typeId] ?? null, $destination)),
        };
    }

    private function structureUnitCostOf(int $typeId): Cost
    {
        $unitPrice = $this->structurePrices[$typeId] ?? null;

        return null === $unitPrice ? Cost::unknown(MissingData::structurePrice($typeId)) : Cost::known($unitPrice);
    }
}
