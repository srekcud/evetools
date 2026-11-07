<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\ParticipantDistribution;
use App\Service\Industry\Project\ProjectDistributionService;

class CompletedProjectFiguresLoader
{
    private const float SECONDS_PER_HOUR = 3600.0;

    public function __construct(
        private readonly IndustryProjectParticipantRepository $participantRepository,
        private readonly ProjectDistributionService $distributionService,
        private readonly CachedIndustryJobRepository $cachedJobRepository,
    ) {
    }

    /**
     * @return list<CompletedProjectFigures>
     */
    public function loadFor(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ownCharacterNames = array_values($user->getCharacters()->map(static fn (Character $character): string => $character->getName())->toArray());

        $figures = [];
        foreach ($this->participantRepository->findAcceptedOnProjectsCompletedBetween($user, $from, $to) as $membership) {
            $project = $membership->getProject();
            $completedAt = $project->getCompletedAt();
            if ($completedAt === null) {
                continue;
            }

            $share = $this->shareOf($membership, $project);
            $items = $project->getItems();
            $hasSingleProduct = $items->count() === 1;
            $product = $hasSingleProduct ? $items->first() : null;

            $figures[] = new CompletedProjectFigures(
                completedAt: $completedAt,
                productTypeId: $product ? $product->getTypeId() : null,
                productTypeName: $product ? $product->getTypeName() : null,
                hasMultipleProducts: $items->count() > 1,
                profit: $share?->profitPart,
                capital: $share?->profitPart === null ? null : $share->totalCostsEngaged,
                slotHours: $this->slotHoursOf($project, $ownCharacterNames),
            );
        }

        return $figures;
    }

    private function shareOf(IndustryProjectParticipant $membership, IndustryProject $project): ?ParticipantDistribution
    {
        $memberId = $membership->getId()->toRfc4122();
        foreach ($this->distributionService->calculateDistribution($project)->members as $share) {
            if ($share->memberId === $memberId) {
                return $share;
            }
        }

        return null;
    }

    /**
     * @param list<string> $ownCharacterNames
     */
    private function slotHoursOf(IndustryProject $project, array $ownCharacterNames): ?float
    {
        $ownEsiJobIds = [];
        foreach ($project->getBomItems() as $bomLine) {
            foreach ($bomLine->getJobMatches() as $match) {
                if (in_array($match->getCharacterName(), $ownCharacterNames, true)) {
                    $ownEsiJobIds[] = $match->getEsiJobId();
                }
            }
        }

        $cachedJobs = $this->cachedJobRepository->findByJobIds($ownEsiJobIds);
        $seconds = 0;
        foreach ($ownEsiJobIds as $esiJobId) {
            $job = $cachedJobs[$esiJobId] ?? null;
            if (!$job instanceof CachedIndustryJob) {
                return null;
            }

            $seconds += $job->getEndDate()->getTimestamp() - $job->getStartDate()->getTimestamp();
        }

        return $seconds / self::SECONDS_PER_HOUR;
    }
}
