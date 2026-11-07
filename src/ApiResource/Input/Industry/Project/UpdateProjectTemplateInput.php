<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateProjectTemplateInput
{
    #[Assert\Length(min: 1, max: 255)]
    public ?string $name = null;

    public ?bool $sharedWithCorporation = null;
}
