<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Enum\ProjectStatus;

final readonly class UnclaimedStock
{
    public static function quantityOf(?int $containerQuantity, int $requiredQuantity, int $claimedQuantity): ?int
    {
        if (null === $containerQuantity) {
            return null;
        }

        return max(0, min($containerQuantity, $requiredQuantity) - $claimedQuantity);
    }

    public static function ofLine(IndustryProject $project, IndustryProjectBomLine $line, ProjectLineFulfillments $fulfillments, int $claimedQuantity): ?int
    {
        if (!\in_array($project->getStatus(), [ProjectStatus::InProgress, ProjectStatus::Selling], true)) {
            return null;
        }

        return self::quantityOf($fulfillments->containerQuantityOf($line), $line->getRequiredQuantity(), $claimedQuantity);
    }

    public static function valueOf(?int $unclaimedQuantity, ?float $launchPrice): ?float
    {
        if (null === $unclaimedQuantity || null === $launchPrice) {
            return null;
        }

        return $unclaimedQuantity * $launchPrice;
    }
}
