<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;

final readonly class ProjectLineFulfillments
{
    private const float FULL_PERCENT = 100.0;

    /**
     * @param array<int, int> $containerQuantitiesByTypeId
     */
    public function __construct(
        private ContainerStockState $state,
        private array $containerQuantitiesByTypeId = [],
    ) {
    }

    public static function none(): self
    {
        return new self(ContainerStockState::None);
    }

    public function fulfilledOf(IndustryProjectBomLine $line): int
    {
        $containerQuantity = $this->containerQuantityOf($line);
        if (null === $containerQuantity) {
            return $line->getFulfilledQuantity();
        }

        return max($line->getFulfilledQuantity(), min($containerQuantity, $line->getRequiredQuantity()));
    }

    public function remainingOf(IndustryProjectBomLine $line): int
    {
        return max(0, $line->getRequiredQuantity() - $this->fulfilledOf($line));
    }

    public function percentOf(IndustryProjectBomLine $line): float
    {
        if ($line->getRequiredQuantity() <= 0) {
            return self::FULL_PERCENT;
        }

        return min(self::FULL_PERCENT, ($this->fulfilledOf($line) / $line->getRequiredQuantity()) * self::FULL_PERCENT);
    }

    public function isFulfilled(IndustryProjectBomLine $line): bool
    {
        return $this->fulfilledOf($line) >= $line->getRequiredQuantity();
    }

    public function containerQuantityOf(IndustryProjectBomLine $line): ?int
    {
        if ($line->isJob() || ContainerStockState::Known !== $this->state) {
            return null;
        }

        return \array_key_exists($line->getTypeId(), $this->containerQuantitiesByTypeId) ? $this->containerQuantitiesByTypeId[$line->getTypeId()] : 0;
    }

    public function stateOf(IndustryProjectBomLine $line): ContainerStockState
    {
        return $line->isJob() ? ContainerStockState::None : $this->state;
    }
}
