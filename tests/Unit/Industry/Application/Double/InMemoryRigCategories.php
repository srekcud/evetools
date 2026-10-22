<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\RigCategories;
use App\Industry\Domain\ActivityKind;

/**
 * IndustryRigCategory of each job product, by activity: the group of the product in manufacturing, the group of the
 * formula in reaction (SdeRigCategories). A product absent from its activity has a group no rig targets: null.
 */
final readonly class InMemoryRigCategories implements RigCategories
{
    /**
     * @param array<int, string> $manufacturing category by manufactured product typeId
     * @param array<int, string> $reaction      category by reaction product typeId
     */
    public function __construct(private array $manufacturing = [], private array $reaction = [])
    {
    }

    public function categoryOf(int $productTypeId, ActivityKind $activity): ?string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => $this->manufacturing[$productTypeId] ?? null,
            ActivityKind::Reaction => $this->reaction[$productTypeId] ?? null,
            default => null,
        };
    }
}
