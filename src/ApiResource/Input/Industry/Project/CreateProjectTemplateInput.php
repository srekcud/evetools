<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

class CreateProjectTemplateInput
{
    public ?string $projectId = null;

    public ?string $name = null;

    public bool $sharedWithCorporation = false;
}
