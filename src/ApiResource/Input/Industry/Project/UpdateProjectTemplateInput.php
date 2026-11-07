<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

class UpdateProjectTemplateInput
{
    public ?string $name = null;

    public ?bool $sharedWithCorporation = null;
}
