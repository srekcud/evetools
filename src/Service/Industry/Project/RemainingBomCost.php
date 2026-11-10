<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;

final readonly class RemainingBomCost
{
    public function of(IndustryProject $project): RemainingCost
    {
        $total = 0.0;
        $unknownLineCount = 0;
        foreach ($project->getBomItems() as $line) {
            if ($line->isJob() || $line->getRemainingQuantity() === 0) {
                continue;
            }

            $estimatedPrice = $line->getEstimatedPrice();
            if (null === $estimatedPrice) {
                ++$unknownLineCount;
                continue;
            }

            $total += $line->getRemainingQuantity() * $estimatedPrice;
        }

        return new RemainingCost($unknownLineCount > 0 ? null : $total, $unknownLineCount);
    }
}
