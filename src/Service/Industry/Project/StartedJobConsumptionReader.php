<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Repository\IndustryProjectBomLineRepository;

class StartedJobConsumptionReader
{
    public function __construct(
        private readonly IndustryProjectBomLineRepository $bomLineRepository,
        private readonly ProjectJobMaterialNeedsLoader $needsLoader,
    ) {
    }

    public function of(IndustryProject $project): StartedJobConsumption
    {
        $jobLines = $this->bomLineRepository->findJobLinesWithMatchesByProject($project);
        if (!$this->hasStartedJob($jobLines)) {
            return StartedJobConsumption::none();
        }

        return StartedJobConsumption::of($jobLines, $this->needsLoader->load($project, $jobLines));
    }

    /**
     * @param array<IndustryProjectBomLine> $jobLines
     */
    private function hasStartedJob(array $jobLines): bool
    {
        foreach ($jobLines as $line) {
            foreach ($line->getJobMatches() as $jobMatch) {
                if (!\in_array($jobMatch->getStatus(), CachedIndustryJob::STATUSES_WITHOUT_OUTPUT, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
