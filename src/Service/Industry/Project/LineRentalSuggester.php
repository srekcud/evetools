<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Industry\Domain\LineRentalRates;
use App\Repository\CachedIndustryJobRepository;

class LineRentalSuggester
{
    private const int SECONDS_PER_DAY = 86_400;

    public function __construct(
        private readonly LineRentalCategoryResolver $categoryResolver,
        private readonly CachedIndustryJobRepository $jobRepository,
    ) {
    }

    public function suggest(User $contributor, IndustryProjectBomLine $line): LineRentalSuggestion
    {
        $category = $this->categoryResolver->categoryOf($line->getTypeId());
        if ($category === null) {
            return $this->unknown(LineRentalUnknownReason::UnknownCategory);
        }

        $matches = $line->getJobMatches()->toArray();
        $cachedJobs = $this->jobRepository->findByJobIds(array_values(array_map(
            static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(),
            $matches,
        )));

        $contributorCharacterIds = $contributor->getCharacters()->map(
            static fn (Character $character): int => $character->getEveCharacterId(),
        )->toArray();

        $days = 0.0;
        $runs = 0;
        $contributorJobCount = 0;
        foreach ($matches as $match) {
            if (!array_key_exists($match->getEsiJobId(), $cachedJobs)) {
                return $this->unknown(LineRentalUnknownReason::JobDataMissing);
            }

            $job = $cachedJobs[$match->getEsiJobId()];
            if (!in_array($job->getCharacter()->getEveCharacterId(), $contributorCharacterIds, true)) {
                continue;
            }

            ++$contributorJobCount;
            $days += $this->daysOccupiedBy($job);
            $runs += $match->getRuns();
        }

        if ($contributorJobCount === 0) {
            return $this->unknown(LineRentalUnknownReason::NoLinkedJob);
        }

        $rates = LineRentalRates::resolve($line->getProject()->getLineRentalRatesOverride(), $contributor->getLineRentalRates());

        return new LineRentalSuggestion(
            $rates->valueOf($category, $days, $runs),
            null,
            $category,
            $days,
            $runs,
            $rates->dayFactor(),
            $rates->ratePerRun($category),
        );
    }

    private function daysOccupiedBy(CachedIndustryJob $job): float
    {
        return ($job->getEndDate()->getTimestamp() - $job->getStartDate()->getTimestamp()) / self::SECONDS_PER_DAY;
    }

    private function unknown(LineRentalUnknownReason $reason): LineRentalSuggestion
    {
        return new LineRentalSuggestion(null, $reason, null, null, null, null, null);
    }
}
