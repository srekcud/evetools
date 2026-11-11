<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum ProjectSurplusSource: string
{
    case Contribution = 'contribution';
    case Production = 'production';
}
