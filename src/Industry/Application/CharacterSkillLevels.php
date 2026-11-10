<?php

declare(strict_types=1);

namespace App\Industry\Application;

interface CharacterSkillLevels
{
    public function levelOf(int $characterId, int $skillTypeId): ?int;
}
