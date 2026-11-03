<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Mining;

class ReprocessingProfileInput
{
    public ?int $characterId = null;

    public ?int $oreProcessingSkillTypeId = null;

    public ?string $location = null;

    public ?string $rigTier = null;

    public ?string $security = null;

    public ?int $implantPercent = null;
}
