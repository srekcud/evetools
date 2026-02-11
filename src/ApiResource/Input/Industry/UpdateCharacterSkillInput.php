<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateCharacterSkillInput
{
    #[Assert\Range(min: 0, max: 5)]
    public ?int $industry = null;

    #[Assert\Range(min: 0, max: 5)]
    public ?int $advancedIndustry = null;

    #[Assert\Range(min: 0, max: 5)]
    public ?int $reactions = null;
}
