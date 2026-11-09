<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

use App\Entity\CachedIndustryJob;

final readonly class OwnProjectJobs
{
    /**
     * @param list<CachedIndustryJob> $jobs
     */
    public function __construct(
        public array $jobs,
        public bool $complete,
    ) {
    }
}
