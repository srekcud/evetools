<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class CreateProjectTemplateInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public ?string $projectId = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    public bool $sharedWithCorporation = false;
}
