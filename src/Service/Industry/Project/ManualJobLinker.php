<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryJobMatcher;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ManualJobLinker
{
    public function __construct(
        private readonly IndustryJobMatcher $jobMatcher,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryProjectJobMatchRepository $jobMatchRepository,
        private readonly DerivedContributionService $derivedContributionService,
    ) {
    }

    public function link(CachedIndustryJob $job, IndustryProjectBomLine $line, ?int $runs = null): IndustryProjectJobMatch
    {
        $esiJobId = $job->getJobId();

        if (!$this->jobMatcher->isJobOfBomLineActivity($job, $line)) {
            throw new BadRequestHttpException('Job activity does not match BOM line activity');
        }

        if ($job->getBlueprintTypeId() !== $this->productRepository->producerOf($line->getTypeId())->getTypeId()) {
            throw new BadRequestHttpException('Job blueprint does not match BOM line blueprint');
        }

        if ($job->hasNoOutput()) {
            throw new BadRequestHttpException("ESI job {$esiJobId} has no output");
        }

        $shares = $this->jobMatchRepository->findByEsiJobIds([$esiJobId]);
        $freeRuns = $job->getRuns() - array_sum(array_map(static fn (IndustryProjectJobMatch $share): int => $share->getRuns(), $shares));

        if ($freeRuns <= 0) {
            throw new BadRequestHttpException("ESI job {$esiJobId} is already linked to a BOM line");
        }

        if (array_any($shares, static fn (IndustryProjectJobMatch $share): bool => $share->getBomLine() === $line)) {
            throw new BadRequestHttpException("ESI job {$esiJobId} already has a share on this BOM line");
        }

        if ($runs !== null && $runs > $freeRuns) {
            throw new BadRequestHttpException("ESI job {$esiJobId} has only {$freeRuns} runs left to share");
        }

        $match = $this->jobMatcher->linkJobToBomLine($job, $line, $runs ?? $freeRuns);
        $this->derivedContributionService->refreshLine($line);

        return $match;
    }
}
