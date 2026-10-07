<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain\Double;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\JobMaterialModifiers;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Recipe;

/**
 * Explicit material modifiers (structure × rig, security included): one per activity, overridable per job product.
 * The goldens fix the formula, not the structure selection (D3 stays in Application).
 */
final readonly class ActivityMaterialModifiers implements JobMaterialModifiers
{
    /**
     * @param array<int, Multiplier> $byProduct by product typeId of the job
     */
    public function __construct(
        private Multiplier $manufacturing,
        private Multiplier $reaction,
        private array $byProduct = [],
    ) {
    }

    public static function none(): self
    {
        return new self(Multiplier::one(), Multiplier::one());
    }

    public function forJob(Recipe $recipe): Multiplier
    {
        return $this->byProduct[$recipe->productTypeId] ?? match ($recipe->activity) {
            ActivityKind::Reaction => $this->reaction,
            default => $this->manufacturing,
        };
    }
}
