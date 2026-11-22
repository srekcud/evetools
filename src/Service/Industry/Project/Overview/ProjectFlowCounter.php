<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Overview;

use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\TypeStationQuantities;

final readonly class ProjectFlowCounter
{
    /**
     * @param list<TypeStationQuantities> $types
     */
    public function count(array $types, int $soldTypeCount): ProjectFlow
    {
        $toBring = 0;
        $onSite = 0;
        $inProduction = 0;
        $out = 0;
        $unknown = 0;

        foreach ($types as $type) {
            if (null === $type->stations) {
                return ProjectFlow::unavailable($type->unknownReason ?? throw new \LogicException(\sprintf('Type %d has no station quantities and no reason.', $type->typeId)));
            }

            foreach ($type->stations as $card) {
                if ($this->hasUnreadQuantity($card)) {
                    ++$unknown;
                }
                $toBring += $this->isToBring($card) ? 1 : 0;
                $onSite += $this->isPositive($card->onSite) ? 1 : 0;
                $inProduction += $card->inProduction > 0 ? 1 : 0;
                $out += $this->isPositive($card->out) ? 1 : 0;
            }
        }

        return new ProjectFlow($toBring, $onSite, $inProduction, $out, $soldTypeCount, $unknown, null);
    }

    private function hasUnreadQuantity(StationQuantity $card): bool
    {
        return null === $card->toBring || null === $card->inTransit || null === $card->onSite || null === $card->out;
    }

    private function isToBring(StationQuantity $card): bool
    {
        return $this->isPositive($card->toBring) || $this->isPositive($card->inTransit);
    }

    private function isPositive(?int $quantity): bool
    {
        return null !== $quantity && $quantity > 0;
    }
}
