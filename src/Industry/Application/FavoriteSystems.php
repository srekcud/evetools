<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

/**
 * Favorite solar systems of the user: one for reactions, one for the Engineering Complex activities.
 */
final readonly class FavoriteSystems
{
    public function __construct(public ?int $manufacturingSystemId, public ?int $reactionSystemId)
    {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public function forActivity(ActivityKind $activity): ?int
    {
        return ActivityKind::Reaction === $activity ? $this->reactionSystemId : $this->manufacturingSystemId;
    }

    public function contains(ProductionStructure $structure, ActivityKind $activity): bool
    {
        $favoriteSystemId = $this->forActivity($activity);

        return null !== $favoriteSystemId && $favoriteSystemId === $structure->solarSystemId;
    }

    public function systemOrFavorite(?ProductionStructure $structure, ActivityKind $activity): ?int
    {
        return $structure->solarSystemId ?? $this->forActivity($activity);
    }
}
