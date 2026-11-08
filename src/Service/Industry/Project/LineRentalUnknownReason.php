<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum LineRentalUnknownReason: string
{
    case UnknownCategory = 'unknown_category';
    case NoLinkedJob = 'no_linked_job';
    case JobDataMissing = 'job_data_missing';
}
