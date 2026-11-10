<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\CharacterSkillLevels;

final readonly class InMemoryCharacterSkillLevels implements CharacterSkillLevels
{
    public function __construct(private array $levelsByEveCharacterIdAndSkillTypeId)
    {
    }

    public function levelOf(int $eveCharacterId, int $skillTypeId): ?int
    {
        return $this->levelsByEveCharacterIdAndSkillTypeId[$eveCharacterId][$skillTypeId] ?? null;
    }
}
