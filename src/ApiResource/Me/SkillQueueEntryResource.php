<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

class SkillQueueEntryResource
{
    public string $characterId;

    public int $skillId;

    public string $skillName;

    public ?int $finishedLevel = null;

    public string $finishDate;

    public int $queueSize;
}
