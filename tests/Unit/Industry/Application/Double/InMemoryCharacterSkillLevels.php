<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\CharacterSkillLevels;

final readonly class InMemoryCharacterSkillLevels implements CharacterSkillLevels
{
    public function __construct(private array $levelsByCharacterIdAndSkillTypeId)
    {
    }

    public function levelOf(int $characterId, int $skillTypeId): ?int
    {
        return $this->levelsByCharacterIdAndSkillTypeId[$characterId][$skillTypeId] ?? null;
    }
}
