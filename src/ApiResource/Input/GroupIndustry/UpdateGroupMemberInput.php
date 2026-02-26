<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateGroupMemberInput
{
    #[Assert\Choice(choices: ['admin', 'member'])]
    public ?string $role = null;

    #[Assert\Choice(choices: ['accepted'])]
    public ?string $status = null;
}
