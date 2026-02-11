<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

class CharacterSkillCollectionResource
{
    /** @var CharacterSkillResource[] */
    public array $characters = [];

    public int $syncedCount = 0;

    public ?string $warning = null;
}
