<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class ReviewContributionInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['approved', 'rejected'])]
    public string $status;
}
