<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum ProjectWorkStationLock: string
{
    case JobLaunched = 'job_launched';
    case PurchaseMade = 'purchase_made';
}
